<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Events\ReservationRequestReceived;
use App\Jobs\FetchReservationEmailsJob;
use App\Models\FailedEmailImport;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\DTO\FetchedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Support\Email\FakeImapMailboxFactory;
use Tests\TestCase;

class FetchReservationEmailsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_skips_mails_when_restaurant_has_no_imap_config(): void
    {
        Event::fake([ReservationRequestReceived::class]);

        $restaurant = Restaurant::factory()->create(['imap_host' => null]);
        $factory = $this->bindFactoryWith([
            $this->makeEmail(messageId: '<skipped@example.com>'),
        ]);

        Bus::dispatchSync(new FetchReservationEmailsJob($restaurant->id));

        $this->assertFalse($factory->opened);
        $this->assertSame(0, ReservationRequest::count());
        $this->assertSame(0, FailedEmailImport::count());
        Event::assertNotDispatched(ReservationRequestReceived::class);
    }

    public function test_it_is_idempotent_for_the_same_message_id(): void
    {
        Event::fake([ReservationRequestReceived::class]);

        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);
        ReservationRequest::factory()->for($restaurant)->create([
            'email_message_id' => '<dup@example.com>',
        ]);

        $email = $this->makeEmail(messageId: '<dup@example.com>');
        $factory = $this->bindFactoryWith([$email]);

        Bus::dispatchSync(new FetchReservationEmailsJob($restaurant->id));

        $this->assertSame(1, ReservationRequest::count());
        $this->assertSame([$email], $factory->mailbox->seen);
        Event::assertNotDispatched(ReservationRequestReceived::class);
    }

    public function test_it_writes_unparsable_mails_to_failed_email_imports(): void
    {
        Event::fake([ReservationRequestReceived::class]);

        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);

        $bad = $this->makeEmail(messageId: '<bad@example.com>', rawBody: 'raw-bad-body');
        $factory = $this->bindFactoryWith([$bad]);
        $factory->mailbox->failOn = '<bad@example.com>';

        Bus::dispatchSync(new FetchReservationEmailsJob($restaurant->id));

        $this->assertSame(1, FailedEmailImport::count());
        $failure = FailedEmailImport::first();
        $this->assertSame($restaurant->id, $failure->restaurant_id);
        $this->assertSame('<bad@example.com>', $failure->message_id);
        $this->assertSame('raw-bad-body', $failure->raw_body);
        $this->assertNotSame('', $failure->error);
        Event::assertNotDispatched(ReservationRequestReceived::class);
    }

    public function test_it_marks_processed_mails_as_seen(): void
    {
        Event::fake([ReservationRequestReceived::class]);

        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);
        $email = $this->makeEmail(
            messageId: '<seen@example.com>',
            body: 'Tisch für 2 Personen am 01.05.2026 um 19:00 Uhr.',
        );
        $factory = $this->bindFactoryWith([$email]);

        Bus::dispatchSync(new FetchReservationEmailsJob($restaurant->id));

        $this->assertSame([$email], $factory->mailbox->seen);
    }

    public function test_it_dispatches_reservation_request_received_per_successful_parse(): void
    {
        Event::fake([ReservationRequestReceived::class]);

        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);
        $first = $this->makeEmail(
            messageId: '<a@example.com>',
            body: 'Tisch für 2 Personen am 01.05.2026 um 19:00 Uhr.',
            senderEmail: 'a@example.com',
        );
        $second = $this->makeEmail(
            messageId: '<b@example.com>',
            body: 'Tisch für 4 Personen am 12.05.2026 um 20:30 Uhr.',
            senderEmail: 'b@example.com',
        );
        $this->bindFactoryWith([$first, $second]);

        Bus::dispatchSync(new FetchReservationEmailsJob($restaurant->id));

        $this->assertSame(2, ReservationRequest::count());
        Event::assertDispatchedTimes(ReservationRequestReceived::class, 2);
    }

    public function test_it_persists_email_envelope_in_raw_payload_for_detail_drawer(): void
    {
        Event::fake([ReservationRequestReceived::class]);

        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);
        $email = $this->makeEmail(
            messageId: '<envelope@example.com>',
            body: 'Tisch für 4 Personen am 12.05.2026 um 19:30 Uhr.',
            senderEmail: 'guest@example.com',
            senderName: 'Anna Probe',
        );
        $this->bindFactoryWith([$email]);

        Bus::dispatchSync(new FetchReservationEmailsJob($restaurant->id));

        $request = ReservationRequest::sole();

        $this->assertSame([
            'body' => 'Tisch für 4 Personen am 12.05.2026 um 19:30 Uhr.',
            'sender_email' => 'guest@example.com',
            'sender_name' => 'Anna Probe',
            'message_id' => '<envelope@example.com>',
        ], $request->raw_payload);
    }

    public function test_it_resolves_the_imap_factory_from_the_service_container(): void
    {
        $restaurant = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);
        $factory = $this->bindFactoryWith([]);

        Bus::dispatchSync(new FetchReservationEmailsJob($restaurant->id));

        $this->assertTrue(
            $factory->opened,
            'Job must resolve ImapMailboxFactory from the container; real Webklex client should never be constructed in tests.',
        );
        $this->assertSame($factory, $this->app->make(ImapMailboxFactory::class));
    }

    /**
     * @param  list<FetchedEmail>  $emails
     */
    private function bindFactoryWith(array $emails): FakeImapMailboxFactory
    {
        $factory = new FakeImapMailboxFactory($emails);
        $this->app->instance(ImapMailboxFactory::class, $factory);

        return $factory;
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
