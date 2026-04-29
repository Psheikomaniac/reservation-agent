<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\MessageDirection;
use App\Enums\ReservationReplyStatus;
use App\Jobs\FetchReservationEmailsJob;
use App\Jobs\SendReservationReplyJob;
use App\Mail\ReservationReplyMail;
use App\Models\ReservationMessage;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\DTO\FetchedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Email\FakeImapMailboxFactory;
use Tests\TestCase;

class ThreadingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_appends_reply_as_thread_message_instead_of_creating_new_reservation(): void
    {
        [$restaurant, $request, $outboundMessageId] = $this->seedRestaurantWithOutboundMessage();

        $reply = $this->makeReply(
            messageId: '<inbound-reply-1@example.com>',
            from: 'guest@example.com',
            inReplyTo: $outboundMessageId,
            subject: 'Re: Reservierung',
        );

        $this->runJobWith($restaurant->id, [$reply]);

        $this->assertSame(
            1,
            ReservationRequest::query()->where('restaurant_id', $restaurant->id)->count(),
            'Threaded reply must NOT create a new reservation.',
        );
        $this->assertSame(
            1,
            ReservationMessage::query()
                ->where('reservation_request_id', $request->id)
                ->where('direction', MessageDirection::In)
                ->count(),
            'Threaded reply must be appended as an inbound ReservationMessage.',
        );
    }

    public function test_it_falls_back_to_new_reservation_when_sender_does_not_match(): void
    {
        [$restaurant, , $outboundMessageId] = $this->seedRestaurantWithOutboundMessage();

        $spoofed = $this->makeReply(
            messageId: '<inbound-spoof-1@example.com>',
            from: 'attacker@example.com',
            inReplyTo: $outboundMessageId,
            subject: 'Re: Tisch fuer 4 Personen am 12.05.2026 um 19:30',
            body: 'Tisch für 4 Personen am 12.05.2026 um 19:30',
        );

        $this->runJobWith($restaurant->id, [$spoofed]);

        $this->assertSame(
            2,
            ReservationRequest::query()->where('restaurant_id', $restaurant->id)->count(),
            'Spoofed reply must fall back to creating a new reservation, not be silently dropped.',
        );
        $this->assertSame(
            0,
            ReservationMessage::query()
                ->where('direction', MessageDirection::In)
                ->count(),
            'Spoofed reply must NOT be appended as a thread message on the original reservation.',
        );
    }

    public function test_it_dedupes_by_message_id_even_with_thread_fallback(): void
    {
        [$restaurant, $request, $outboundMessageId] = $this->seedRestaurantWithOutboundMessage();

        $reply = $this->makeReply(
            messageId: '<inbound-dup-1@example.com>',
            from: 'guest@example.com',
            inReplyTo: $outboundMessageId,
            subject: 'Re: Reservierung',
        );

        $this->runJobWith($restaurant->id, [$reply]);
        $this->runJobWith($restaurant->id, [$reply]);

        $this->assertSame(
            1,
            ReservationMessage::query()
                ->where('reservation_request_id', $request->id)
                ->where('direction', MessageDirection::In)
                ->count(),
            'Same Message-ID must not be ingested twice as a thread message.',
        );
        $this->assertSame(
            1,
            ReservationRequest::query()->where('restaurant_id', $restaurant->id)->count(),
            'Same Message-ID must not flip into a new reservation on a re-fetch.',
        );
    }

    /**
     * @return array{0: Restaurant, 1: ReservationRequest, 2: string}
     */
    private function seedRestaurantWithOutboundMessage(): array
    {
        $restaurant = Restaurant::factory()->create([
            'imap_host' => 'mail.example.com',
            'imap_username' => 'mailbox@example.com',
            'imap_password' => 'secret',
        ]);
        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'guest_email' => 'guest@example.com',
        ]);
        $outbound = ReservationMessage::factory()
            ->outbound()
            ->forReservationRequest($request)
            ->create();

        return [$restaurant, $request, $outbound->message_id];
    }

    private function makeReply(
        string $messageId,
        string $from,
        string $inReplyTo,
        string $subject = '',
        string $body = '',
    ): FetchedEmail {
        return new FetchedEmail(
            messageId: $messageId,
            body: $body,
            senderEmail: $from,
            senderName: null,
            rawHeaders: 'From: '.$from,
            rawBody: $body,
            inReplyTo: $inReplyTo,
            references: $inReplyTo,
            subject: $subject,
            toAddress: 'mailbox@example.com',
        );
    }

    /**
     * @param  list<FetchedEmail>  $emails
     */
    private function runJobWith(int $restaurantId, array $emails): void
    {
        $factory = new FakeImapMailboxFactory($emails);

        $this->app->instance(ImapMailboxFactory::class, $factory);

        Bus::dispatchSync(new FetchReservationEmailsJob($restaurantId));
    }

    public function test_it_generates_stable_message_id_for_outbound_mail(): void
    {
        config()->set('mail.from.address', 'noreply@restaurant.example');

        $reply = $this->makeApprovedReply();

        $mail = new ReservationReplyMail($reply);

        $this->assertMatchesRegularExpression(
            '/^reservation-'.$reply->id.'-[0-9a-f]{16}@restaurant\.example$/',
            $mail->messageId,
            'Outbound Message-ID must follow <reservation-{reply_id}-{16hex}@{domain}>.',
        );

        $rendered = $mail->render();
        $this->assertNotEmpty($rendered);
    }

    public function test_message_id_falls_back_to_localhost_when_from_address_is_unparseable(): void
    {
        config()->set('mail.from.address', null);

        $reply = $this->makeApprovedReply();

        $mail = new ReservationReplyMail($reply);

        $this->assertStringEndsWith('@localhost', $mail->messageId);
    }

    public function test_it_inserts_subject_marker_for_outbound_mail(): void
    {
        $reply = $this->makeApprovedReply(restaurantName: 'Le Bistro');

        $mail = new ReservationReplyMail($reply);

        $mail->assertHasSubject('Reservierung bei Le Bistro [Res #'.$reply->reservation_request_id.']');
    }

    public function test_it_does_not_double_insert_subject_marker_if_already_present(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Le Bistro']);
        $request = ReservationRequest::factory()->create(['restaurant_id' => $restaurant->id]);

        // Force the restaurant name to already contain the marker for this
        // exact reservation id, so the default subject template would emit
        // a duplicate marker if buildSubjectWithMarker did not deduplicate.
        $restaurant->forceFill(['name' => 'Le Bistro [Res #'.$request->id.']'])->save();

        $reply = ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Approved,
        ]);

        $mail = new ReservationReplyMail($reply->fresh());

        $subject = $mail->envelope()->subject;

        $marker = '[Res #'.$request->id.']';
        $this->assertSame(
            1,
            substr_count((string) $subject, $marker),
            'Subject marker must not be inserted twice when the source subject already contains it.',
        );
    }

    public function test_outbound_mail_send_persists_message_id_on_reservation_reply(): void
    {
        Mail::fake();

        $reply = $this->makeApprovedReply(guestEmail: 'guest@example.com');

        Bus::dispatchSync(new SendReservationReplyJob($reply->id));

        $reply->refresh();

        $this->assertNotNull($reply->outbound_message_id);
        $this->assertMatchesRegularExpression(
            '/^reservation-'.$reply->id.'-[0-9a-f]{16}@/',
            (string) $reply->outbound_message_id,
        );

        Mail::assertSent(ReservationReplyMail::class, function (ReservationReplyMail $sent) use ($reply): bool {
            return $sent->messageId === $reply->outbound_message_id;
        });
    }

    private function makeApprovedReply(string $restaurantName = 'La Trattoria', string $guestEmail = 'guest@example.com'): ReservationReply
    {
        $restaurant = Restaurant::factory()->create(['name' => $restaurantName]);
        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'guest_email' => $guestEmail,
        ]);

        return ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Approved,
        ]);
    }
}
