<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\MessageDirection;
use App\Jobs\FetchReservationEmailsJob;
use App\Models\ReservationMessage;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\DTO\FetchedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
}
