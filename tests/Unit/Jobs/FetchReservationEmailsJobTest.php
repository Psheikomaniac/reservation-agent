<?php

namespace Tests\Unit\Jobs;

use App\Events\ReservationRequestReceived;
use App\Jobs\FetchReservationEmailsJob;
use App\Models\FailedEmailImport;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailbox;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\DTO\FetchedEmail;
use App\Services\Email\EmailReservationParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    private function runJob(int $restaurantId, ImapMailboxFactory $factory): void
    {
        $job = new FetchReservationEmailsJob($restaurantId);
        $job->handle($factory, new EmailReservationParser);
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

final class FakeImapMailboxFactory implements ImapMailboxFactory
{
    public bool $opened = false;

    public FakeImapMailbox $mailbox;

    /**
     * @param  list<FetchedEmail>  $emails
     */
    public function __construct(array $emails)
    {
        $this->mailbox = new FakeImapMailbox($emails);
    }

    public function open(Restaurant $restaurant): ImapMailbox
    {
        $this->opened = true;

        return $this->mailbox;
    }
}

final class FakeImapMailbox implements ImapMailbox
{
    /** @var list<FetchedEmail> */
    public array $seen = [];

    public ?string $failOn = null;

    /**
     * @param  list<FetchedEmail>  $emails
     */
    public function __construct(private array $emails) {}

    public function fetchUnseen(): array
    {
        return $this->emails;
    }

    public function markSeen(FetchedEmail $email): void
    {
        if ($this->failOn !== null && $email->messageId === $this->failOn) {
            throw new \RuntimeException('boom');
        }
        $this->seen[] = $email;
    }
}
