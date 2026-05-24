<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MessageDirection;
use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Events\ReservationRequestReceived;
use App\Http\Requests\StoreReservationRequest;
use App\Jobs\SendReservationReplyJob;
use App\Mail\ReservationReplyMail;
use App\Models\ReservationMessage;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\AI\OpenAiReplyGenerator;
use App\Services\AI\ReservationContextBuilder;
use App\Services\AutoSend\WebSyncConfirmDecider;
use App\Services\Availability\SlotAvailability;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Psr\Log\LoggerInterface;
use Throwable;

final class PublicReservationController extends Controller
{
    public function __construct(
        private readonly WebSyncConfirmDecider $decider,
        private readonly ReservationContextBuilder $contextBuilder,
        private readonly SlotAvailability $availability,
        private readonly LoggerInterface $logger,
    ) {}

    public function create(Restaurant $restaurant): Response
    {
        return Inertia::render('Public/ReservationForm', [
            'restaurant' => [
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'tonality' => $restaurant->tonality->value,
            ],
            'maxPartySize' => ReservationRequest::MAX_PARTY_SIZE,
        ]);
    }

    public function store(StoreReservationRequest $request, Restaurant $restaurant): RedirectResponse|Response
    {
        if ($request->filled('website')) {
            return redirect()->route('public.reservations.thanks', $restaurant);
        }

        $validated = $request->validated();

        $reservationRequest = ReservationRequest::create([
            'restaurant_id' => $restaurant->id,
            'source' => ReservationSource::WebForm,
            'status' => ReservationStatus::New,
            'guest_name' => $validated['guest_name'],
            'guest_email' => $validated['guest_email'],
            'guest_phone' => $validated['guest_phone'] ?? null,
            'party_size' => $validated['party_size'],
            'desired_at' => Timezone::localToUtc($validated['desired_at'], $restaurant->timezone),
            'message' => $validated['message'] ?? null,
            'raw_payload' => $validated,
        ]);

        // PRD-014: only web-form submissions are eligible for the synchronous
        // confirmation; email/phone reservations run their own pipelines.
        if ($reservationRequest->source === ReservationSource::WebForm) {
            $confirmation = $this->attemptSyncConfirm($reservationRequest, $restaurant);

            if ($confirmation !== null) {
                return $confirmation;
            }
        }

        // V1 path: hand the request to the async draft pipeline for owner review.
        ReservationRequestReceived::dispatch($reservationRequest);

        return redirect()->route('public.reservations.thanks', $restaurant);
    }

    public function thanks(Restaurant $restaurant): Response
    {
        return Inertia::render('Public/Thanks', [
            'restaurant' => [
                'name' => $restaurant->name,
            ],
        ]);
    }

    /**
     * Try to confirm the reservation synchronously (PRD-014). Returns the
     * confirmation page on success, or null to signal the caller to fall back
     * to the V1 path. Any failure — gate skip, OpenAI timeout, lost table
     * race, SMTP error — resolves to null without surfacing a UI error.
     */
    private function attemptSyncConfirm(ReservationRequest $reservation, Restaurant $restaurant): ?Response
    {
        if (! $this->decider->decide($reservation)->shouldProceed()) {
            return null;
        }

        try {
            // Generate the reply BEFORE the transaction so the up-to-5s OpenAI
            // call never holds the table lock. generateSync throws rather than
            // returning the neutral fallback, so a confirmation always carries
            // a real reply. The generator is resolved here (not constructor-
            // injected) so a missing/invalid OPENAI_API_KEY is caught by this
            // try-block and degrades to the V1 path — V1 submits never build the
            // OpenAI client (the same late-resolution trick as
            // GenerateReservationReplyJob).
            $context = $this->contextBuilder->build($reservation);
            $body = app(OpenAiReplyGenerator::class)->generateSync($context, $restaurant, 5);

            $confirmed = DB::transaction(function () use ($reservation, $context, $body): bool {
                // Serialize concurrent bookings for this restaurant's slot. The
                // route restaurant (not an authenticated tenant) scopes the lock;
                // RestaurantScope no-ops without a user. lockForUpdate is a no-op
                // on SQLite, so the in-lock re-check below is what actually
                // rejects a slot taken since the decider ran (race vs PRD-012).
                Table::query()
                    ->where('restaurant_id', $reservation->restaurant_id)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->get();

                $combination = $this->availability->suggestTableCombination(
                    $reservation->restaurant_id,
                    CarbonImmutable::instance($reservation->desired_at),
                    $reservation->party_size,
                );

                if ($combination === null) {
                    return false;
                }

                $reply = ReservationReply::create([
                    'reservation_request_id' => $reservation->id,
                    'status' => ReservationReplyStatus::Draft,
                    'body' => $body,
                    'ai_prompt_snapshot' => $context + [
                        'original_body' => $body,
                        'model' => (string) config('reservations.ai.openai_model', 'gpt-4o-mini'),
                        'fallback' => false,
                    ],
                    'sync_confirm' => true,
                ]);

                foreach ($combination->tableIds as $tableId) {
                    ReservationTableAssignment::create([
                        'reservation_request_id' => $reservation->id,
                        'table_id' => $tableId,
                        'assigned_at' => now(),
                        'assigned_by_user_id' => null,
                    ]);
                }

                // Persist every DB side-effect (outbound message, reply -> sent,
                // request -> confirmed) FIRST, then physically send as the very
                // last step. forceFill bypasses the manual new->… state machine,
                // exactly as SendReservationReplyJob does for the auto pipeline.
                $mail = $this->recordSentReply($reply, $reservation);
                $reservation->forceFill(['status' => ReservationStatus::Confirmed])->save();

                // Non-queued send LAST so an SMTP failure rolls back the whole
                // confirmation and the request stays `new` for V1 (PRD-014). The
                // only residual window is a commit failure after a successful
                // send — the irreducible DB+mail dual-write, and vanishingly rare.
                Mail::to((string) $reservation->guest_email)->send($mail);

                return true;
            });
        } catch (Throwable $e) {
            // Class only — never guest data or mail content (PRD-014).
            $this->logger->warning('web sync confirm failed, falling back to v1 path', [
                'reservation_request_id' => $reservation->id,
                'reason' => $e::class,
            ]);

            return null;
        }

        if (! $confirmed) {
            return null;
        }

        // Rendered AFTER the committed transaction and outside the try, so a
        // formatting error here surfaces as an error rather than silently
        // falling through to V1 and double-confirming an already-mailed guest.
        return $this->renderConfirmation($reservation, $restaurant);
    }

    /**
     * Record the outbound message and flag the reply as sent, then return the
     * Mailable for the caller to dispatch. The outbound-message bookkeeping
     * mirrors {@see SendReservationReplyJob} so threading (PRD-006) and the
     * drawer history stay consistent; the parent request transition differs
     * (sync-confirm -> confirmed, not replied) and is the caller's job.
     */
    private function recordSentReply(ReservationReply $reply, ReservationRequest $reservation): ReservationReplyMail
    {
        $email = (string) $reservation->guest_email;
        $mail = new ReservationReplyMail($reply, syncConfirm: true);

        $fromAddress = (string) (config('mail.from.address') ?: 'noreply@localhost');
        $subject = (string) $mail->envelope()->subject;

        ReservationMessage::create([
            'reservation_request_id' => $reply->reservation_request_id,
            'direction' => MessageDirection::Out,
            'message_id' => $mail->messageId,
            'subject' => $subject,
            'from_address' => $fromAddress,
            'to_address' => $email,
            'body_plain' => $reply->body,
            'raw_headers' => "Message-ID: <{$mail->messageId}>\nFrom: {$fromAddress}\nTo: {$email}\nSubject: {$subject}",
            'sent_at' => now(),
        ]);

        $reply->forceFill([
            'status' => ReservationReplyStatus::Sent,
            'sent_at' => now(),
            'outbound_message_id' => $mail->messageId,
        ])->save();

        return $mail;
    }

    private function renderConfirmation(ReservationRequest $reservation, Restaurant $restaurant): Response
    {
        $localDesiredAt = CarbonImmutable::instance($reservation->desired_at)
            ->setTimezone($restaurant->timezone);

        return Inertia::render('Public/ConfirmedSync', [
            'restaurant' => [
                'name' => $restaurant->name,
            ],
            'reservation' => [
                'date' => $localDesiredAt->format('Y-m-d'),
                'time' => $localDesiredAt->format('H:i'),
                'party_size' => $reservation->party_size,
                'guest_email_masked' => $this->maskEmail((string) $reservation->guest_email),
            ],
        ]);
    }

    /**
     * Mask a guest email for the public confirmation page: first character of
     * the local part, then the full domain (e.g. `a…@gmail.com`).
     */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        if ($at === false || $at === 0) {
            return $email;
        }

        return $email[0].'…@'.substr($email, $at + 1);
    }
}
