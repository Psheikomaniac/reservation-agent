<?php

declare(strict_types=1);

namespace Tests\Feature\AutoSend;

use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationStatus;
use App\Enums\SendMode;
use App\Jobs\GenerateReservationReplyJob;
use App\Jobs\ScheduledAutoSendJob;
use App\Mail\ReservationReplyMail;
use App\Models\AutoSendAudit;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\AutoSendDecision;
use App\Services\AI\Contracts\ReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_audit_entry_on_every_auto_send_decision(): void
    {
        // Manual mode → expect a single audit row recording `manual / mode_manual`.
        Mail::fake();
        $request = $this->makeRequestForRestaurantWithMode(SendMode::Manual);
        $this->bindReplyGenerator('Generierter Text.');

        (new GenerateReservationReplyJob($request->id))->handle();

        $reply = ReservationReply::withoutGlobalScopes()
            ->where('reservation_request_id', $request->id)
            ->sole();

        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);
        $this->assertSame(SendMode::Manual, $reply->send_mode_at_creation);

        /** @var array{decision: string, reason: string} $decisionSnapshot */
        $decisionSnapshot = $reply->auto_send_decision;
        $this->assertSame(AutoSendDecision::DECISION_MANUAL, $decisionSnapshot['decision']);
        $this->assertSame('mode_manual', $decisionSnapshot['reason']);

        $this->assertDatabaseHas('auto_send_audits', [
            'reservation_reply_id' => $reply->id,
            'send_mode' => SendMode::Manual->value,
            'decision' => AutoSendAudit::DECISION_MANUAL,
            'reason' => 'mode_manual',
            'triggered_by_user_id' => null,
        ]);
    }

    public function test_it_does_not_send_mail_in_shadow_mode(): void
    {
        Mail::fake();
        Queue::fake();

        $request = $this->makeRequestForRestaurantWithMode(SendMode::Shadow);
        $this->bindReplyGenerator('Schatten-Antwort.');

        (new GenerateReservationReplyJob($request->id))->handle();

        Mail::assertNothingSent();
        Mail::assertNotQueued(ReservationReplyMail::class);

        $reply = ReservationReply::withoutGlobalScopes()
            ->where('reservation_request_id', $request->id)
            ->sole();

        $this->assertSame(ReservationReplyStatus::Shadow, $reply->status);
        $this->assertDatabaseHas('auto_send_audits', [
            'reservation_reply_id' => $reply->id,
            'decision' => AutoSendAudit::DECISION_SHADOW,
            'reason' => 'mode_shadow',
        ]);
    }

    public function test_it_dispatches_scheduled_auto_send_with_60_second_delay_in_auto_mode(): void
    {
        Queue::fake();

        $request = $this->makeRequestForRestaurantWithMode(SendMode::Auto, returningGuest: true);
        $this->bindReplyGenerator('Auto-Antwort.');

        (new GenerateReservationReplyJob($request->id))->handle();

        $reply = ReservationReply::withoutGlobalScopes()
            ->where('reservation_request_id', $request->id)
            ->sole();

        $this->assertSame(ReservationReplyStatus::ScheduledAutoSend, $reply->status);
        $this->assertNotNull($reply->auto_send_scheduled_for);
        $this->assertEqualsWithDelta(
            now()->addSeconds(GenerateReservationReplyJob::AUTO_SEND_CANCEL_WINDOW_SECONDS)->timestamp,
            $reply->auto_send_scheduled_for->timestamp,
            5,
            'Scheduled-for should be ~now() + 60s.'
        );

        Queue::assertPushed(ScheduledAutoSendJob::class, function ($job) use ($reply) {
            return $job->replyId === $reply->id
                && $job->delay === GenerateReservationReplyJob::AUTO_SEND_CANCEL_WINDOW_SECONDS;
        });

        $this->assertDatabaseHas('auto_send_audits', [
            'reservation_reply_id' => $reply->id,
            'decision' => AutoSendAudit::DECISION_AUTO_SEND,
            'reason' => 'mode_auto',
        ]);
    }

    public function test_it_never_logs_guest_email_or_reply_body_in_audits(): void
    {
        Mail::fake();

        $email = 'guest-with-pii@example.test';
        $request = $this->makeRequestForRestaurantWithMode(SendMode::Manual, guestEmail: $email);
        $this->bindReplyGenerator('Vertraulicher Antworttext mit Gästedetails.');

        (new GenerateReservationReplyJob($request->id))->handle();

        $audit = AutoSendAudit::query()->sole();

        $serialized = json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($serialized);
        $this->assertStringNotContainsString($email, $serialized, 'Audit row must not contain the guest email.');
        $this->assertStringNotContainsString('Vertraulicher Antworttext', $serialized, 'Audit row must not contain the reply body.');
    }

    public function test_existing_v1_manual_default_remains_compatible(): void
    {
        // Brand-new restaurant — default send_mode is `manual` per migration.
        // The pipeline must produce the same Draft output PRD-005 expects,
        // with the new audit/snapshot fields filled in but not changing the
        // V1.0 behaviour (no mail, status = Draft).
        Mail::fake();

        $restaurant = Restaurant::factory()->create();
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'desired_at' => now()->addDays(5),
        ]);
        $this->bindReplyGenerator('V1-konformer Antworttext.');

        (new GenerateReservationReplyJob($request->id))->handle();

        $reply = ReservationReply::withoutGlobalScopes()
            ->where('reservation_request_id', $request->id)
            ->sole();

        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);
        $this->assertSame(SendMode::Manual, $reply->send_mode_at_creation);
        Mail::assertNothingSent();
    }

    private function makeRequestForRestaurantWithMode(
        SendMode $mode,
        bool $returningGuest = true,
        ?string $guestEmail = null,
    ): ReservationRequest {
        $restaurant = Restaurant::factory()->create();
        $restaurant->forceFill(['send_mode' => $mode])->save();

        $email = $guestEmail ?? 'guest@example.com';

        if ($returningGuest) {
            // Hard-gate `first_time_guest` would otherwise short-circuit
            // even Manual / Shadow modes (gates run before mode dispatch),
            // making the audit reason `first_time_guest` instead of the
            // expected `mode_*`. We seed a confirmed prior reservation so
            // the gate passes and we exercise the mode-dispatch arm.
            ReservationRequest::factory()->forRestaurant($restaurant)->create([
                'guest_email' => $email,
                'status' => ReservationStatus::Confirmed,
            ]);
        }

        return ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'guest_email' => $email,
            'desired_at' => now()->addDays(5),
            'party_size' => 4,
            'needs_manual_review' => false,
        ]);
    }

    private function bindReplyGenerator(string $body): void
    {
        $this->app->bind(ReplyGenerator::class, fn () => new class($body) implements ReplyGenerator
        {
            public function __construct(private readonly string $body) {}

            public function generate(array $context): string
            {
                return $this->body;
            }
        });
    }
}
