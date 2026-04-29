<?php

namespace Tests\Unit\Jobs;

use App\Events\ReservationRequestReceived;
use App\Jobs\FetchReservationEmailsJob;
use App\Models\FailedEmailImport;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\DTO\FetchedEmail;
use App\Services\Email\EmailReservationParser;
use App\Services\Email\ThreadResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Support\Email\FakeImapMailboxFactory;
use Tests\Support\Email\ThrowingImapMailboxFactory;
use Tests\TestCase;

class FetchReservationEmailsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_a_no_op_when_restaurant_has_no_imap_host(): void
    {
        $restaurant = Restaurant::factory()->create(['imap_host' => null]);
        $factory = new FakeImapMailboxFactory([]);

        $this->runJob($restaurant->id, $factory);

        $this->assertFalse($factory->opened);
        $this->assertSame(0, ReservationRequest::count());
    }

    public function test_it_is_a_no_op_when_restaurant_does_not_exist(): void
    {
        $factory = new FakeImapMailboxFactory([]);

        $this->runJob(9999, $factory);

        $this->assertFalse($factory->opened);
    }

    public function test_it_parses_unseen_messages_and_creates_reservation_requests(): void
    {
        Event::fake([ReservationRequestReceived::class]);

        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);
        $email = $this->makeEmail(
            messageId: '<abc@example.com>',
            body: 'Tisch für 4 Personen am 12.05.2026 um 19:30 Uhr. Gruß, Anna Müller',
            senderEmail: 'anna@example.com',
            senderName: 'Anna Müller',
        );
        $factory = new FakeImapMailboxFactory([$email]);

        $this->runJob($restaurant->id, $factory);

        $this->assertSame(1, ReservationRequest::count());
        $request = ReservationRequest::first();
        $this->assertSame('<abc@example.com>', $request->email_message_id);
        $this->assertSame('Anna Müller', $request->guest_name);
        $this->assertSame(4, $request->party_size);
        $this->assertFalse($request->needs_manual_review);
        $this->assertSame([$email], $factory->mailbox->seen);
        Event::assertDispatched(ReservationRequestReceived::class);
    }

    public function test_it_skips_messages_whose_message_id_is_already_imported(): void
    {
        Event::fake([ReservationRequestReceived::class]);

        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);
        ReservationRequest::factory()->for($restaurant)->create([
            'email_message_id' => '<dup@example.com>',
        ]);

        $email = $this->makeEmail(messageId: '<dup@example.com>');
        $factory = new FakeImapMailboxFactory([$email]);

        $this->runJob($restaurant->id, $factory);

        $this->assertSame(1, ReservationRequest::count());
        $this->assertSame([$email], $factory->mailbox->seen);
        Event::assertNotDispatched(ReservationRequestReceived::class);
    }

    public function test_it_isolates_failures_per_message_and_keeps_processing(): void
    {
        Event::fake([ReservationRequestReceived::class]);

        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);

        $good = $this->makeEmail(
            messageId: '<good@example.com>',
            body: 'Tisch für 2 Personen am 01.05.2026 um 19:00 Uhr.',
            senderEmail: 'ok@example.com',
        );
        $bad = $this->makeEmail(messageId: '<bad@example.com>', rawBody: 'raw-bad');

        $factory = new FakeImapMailboxFactory([$bad, $good]);
        $factory->mailbox->failOn = '<bad@example.com>';

        $this->runJob($restaurant->id, $factory);

        // Both reservations persist (bad failed on IMAP markSeen *after* DB create).
        $this->assertSame(2, ReservationRequest::count());

        $this->assertSame(1, FailedEmailImport::count());
        $failure = FailedEmailImport::first();
        $this->assertSame($restaurant->id, $failure->restaurant_id);
        $this->assertSame('<bad@example.com>', $failure->message_id);
        $this->assertSame('raw-bad', $failure->raw_body);
        $this->assertNotSame('', $failure->error);

        // markSeen threw for bad; good was still processed and marked seen.
        $this->assertSame([$good], $factory->mailbox->seen);

        // Event is only dispatched after markSeen succeeds — so bad is skipped.
        Event::assertDispatchedTimes(ReservationRequestReceived::class, 1);
    }

    public function test_retry_policy_exposes_three_tries_and_exponential_backoff(): void
    {
        $job = new FetchReservationEmailsJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame([30, 120, 300], $job->backoff());
    }

    public function test_connection_failures_bubble_out_of_handle_so_the_queue_can_retry(): void
    {
        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);
        $factory = new ThrowingImapMailboxFactory(new RuntimeException('imap connect failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('imap connect failed');

        $this->runJob($restaurant->id, $factory);
    }

    public function test_failed_hook_logs_context_without_credentials(): void
    {
        Log::spy();

        $restaurant = Restaurant::factory()->create([
            'imap_host' => 'imap.example.com',
            'imap_username' => 'mailbox@example.com',
            'imap_password' => 'super-secret-password-xyz',
        ]);

        $job = new FetchReservationEmailsJob($restaurant->id);
        $job->failed(new RuntimeException('connection refused'));

        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) use ($restaurant) {
            $this->assertSame('reservation.imap.fetch_failed', $message);
            $this->assertSame($restaurant->id, $context['restaurant_id']);
            $this->assertSame('imap.example.com', $context['host']);
            $this->assertSame('mailbox@example.com', $context['username']);
            $this->assertSame(RuntimeException::class, $context['exception']);
            $this->assertSame('connection refused', $context['message']);

            $this->assertArrayNotHasKey('password', $context);
            $this->assertArrayNotHasKey('imap_password', $context);

            $serialised = json_encode($context);
            $this->assertStringNotContainsString('super-secret-password-xyz', (string) $serialised);

            return true;
        });
    }

    private function runJob(int $restaurantId, ImapMailboxFactory $factory): void
    {
        $job = new FetchReservationEmailsJob($restaurantId);
        $job->handle($factory, new EmailReservationParser, new ThreadResolver(new NullLogger));
    }

    private function makeEmail(
        string $messageId = '<m@example.com>',
        string $body = 'Tisch für 2 Personen am 01.05.2026 um 19:00 Uhr',
        string $senderEmail = 'guest@example.com',
        ?string $senderName = 'Guest',
        string $rawHeaders = 'raw-headers',
        string $rawBody = 'raw-body',
    ): FetchedEmail {
        return new FetchedEmail(
            messageId: $messageId,
            body: $body,
            senderEmail: $senderEmail,
            senderName: $senderName,
            rawHeaders: $rawHeaders,
            rawBody: $rawBody,
        );
    }
}
