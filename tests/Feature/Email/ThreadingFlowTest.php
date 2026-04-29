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
use App\Models\User;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\DTO\FetchedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Email\FakeImapMailboxFactory;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

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

    public function test_it_builds_references_chain_on_second_outbound_mail(): void
    {
        $reply = $this->makeApprovedReply();

        $previousId = '<reservation-99-aaaaaaaaaaaaaaaa@restaurant.example>';
        ReservationMessage::factory()
            ->outbound()
            ->forReservationRequest($reply->reservationRequest)
            ->create([
                'message_id' => $previousId,
                'sent_at' => now()->subHour(),
            ]);

        $mail = new ReservationReplyMail($reply->fresh());
        $headers = $mail->headers();

        $this->assertSame([$previousId], $headers->references);
        $this->assertSame('<'.$previousId.'>', $headers->text['In-Reply-To'] ?? null);
    }

    public function test_first_outbound_mail_carries_no_references_or_in_reply_to(): void
    {
        $reply = $this->makeApprovedReply();

        $mail = new ReservationReplyMail($reply);
        $headers = $mail->headers();

        $this->assertSame([], $headers->references);
        $this->assertArrayNotHasKey('In-Reply-To', $headers->text);
    }

    public function test_it_persists_outbound_reservation_message_after_send(): void
    {
        Mail::fake();

        $reply = $this->makeApprovedReply(guestEmail: 'guest@example.com');

        Bus::dispatchSync(new SendReservationReplyJob($reply->id));

        $outbound = ReservationMessage::query()
            ->where('reservation_request_id', $reply->reservation_request_id)
            ->where('direction', MessageDirection::Out)
            ->first();

        $this->assertNotNull($outbound, 'A successful send must persist an outbound ReservationMessage row.');
        $this->assertSame($reply->fresh()->outbound_message_id, $outbound->message_id);
        $this->assertSame('guest@example.com', $outbound->to_address);
        $this->assertSame($reply->body, $outbound->body_plain);
        $this->assertNotNull($outbound->sent_at);
    }

    public function test_it_trims_references_to_last_ten_message_ids(): void
    {
        $reply = $this->makeApprovedReply();
        $request = $reply->reservationRequest;

        $allIds = [];
        for ($i = 1; $i <= 15; $i++) {
            $id = sprintf('<reservation-%d-%s@restaurant.example>', $i, str_repeat('0', 15).$i);
            $allIds[] = $id;
            ReservationMessage::factory()
                ->outbound()
                ->forReservationRequest($request)
                ->create([
                    'message_id' => $id,
                    'sent_at' => now()->subHours(15 - $i + 1),
                ]);
        }

        $mail = new ReservationReplyMail($reply->fresh());
        $headers = $mail->headers();

        $this->assertCount(10, $headers->references);
        $this->assertSame(array_slice($allIds, 5), $headers->references, 'Should keep the most recent 10 ids in chronological order.');
        $this->assertSame('<'.end($allIds).'>', $headers->text['In-Reply-To']);
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

    public function test_it_lists_thread_messages_in_detail_drawer_chronologically(): void
    {
        $restaurant = Restaurant::factory()->create();
        $request = ReservationRequest::factory()->create(['restaurant_id' => $restaurant->id]);
        $user = User::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Operator Anna']);

        $first = ReservationMessage::factory()->outbound()->forReservationRequest($request)->create([
            'subject' => 'Reservierung bei XYZ [Res #'.$request->id.']',
            'sent_at' => now()->subHours(3),
            'created_at' => now()->subHours(3),
        ]);
        ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Sent,
            'outbound_message_id' => $first->message_id,
            'approved_by' => $user->id,
        ]);
        $second = ReservationMessage::factory()->inbound()->forReservationRequest($request)->create([
            'subject' => 'Re: Reservierung bei XYZ [Res #'.$request->id.']',
            'received_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('reservations.messages.index', $request));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.direction', 'out')
            ->assertJsonPath('data.0.approved_by', 'Operator Anna')
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.1.direction', 'in')
            ->assertJsonPath('data.1.approved_by', null);
    }

    public function test_it_forbids_cross_tenant_access_to_messages_endpoint(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $requestOnA = ReservationRequest::factory()->create(['restaurant_id' => $restaurantA->id]);
        $userOfB = User::factory()->create(['restaurant_id' => $restaurantB->id]);

        $this->actingAs($userOfB)
            ->getJson(route('reservations.messages.index', $requestOnA))
            ->assertForbidden();
    }

    public function test_it_returns_404_for_unknown_reservation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('reservations.messages.index', 999_999))
            ->assertNotFound();
    }

    public function test_it_does_not_expose_raw_headers_in_resource(): void
    {
        $restaurant = Restaurant::factory()->create();
        $request = ReservationRequest::factory()->create(['restaurant_id' => $restaurant->id]);
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);

        $secret = 'X-Internal-Token: super-secret-do-not-leak';
        ReservationMessage::factory()->inbound()->forReservationRequest($request)->create([
            'raw_headers' => $secret,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('reservations.messages.index', $request));

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.raw_headers');
        $this->assertStringNotContainsString($secret, $response->getContent() ?: '');
    }

    public function test_fetch_job_threads_an_inbound_eml_fixture_via_in_reply_to_header_end_to_end(): void
    {
        // Reuses the .eml fixture from #210. The flow under test:
        //   .eml → Webklex Message → FetchedEmail (via the same conversion
        //   the production WebklexImapMailbox does) → fed through the fake
        //   mailbox factory → FetchReservationEmailsJob → ThreadResolver →
        //   ReservationMessage row appended on the existing reservation,
        //   no new ReservationRequest created.
        $restaurant = Restaurant::factory()->create([
            'imap_host' => 'mail.example.com',
            'imap_username' => 'mailbox@example.com',
            'imap_password' => 'secret',
        ]);
        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'guest_email' => 'anna.mueller@example.com',
        ]);
        ReservationMessage::factory()
            ->outbound()
            ->forReservationRequest($request)
            ->create([
                'message_id' => 'outbound-thread-id-001@restaurant.example',
                'sent_at' => now()->subHour(),
            ]);

        $email = $this->loadFixtureAsEmail(__DIR__.'/../../Fixtures/emails/threading/reply-with-in-reply-to.eml');

        $this->runJobWith($restaurant->id, [$email]);

        $this->assertSame(
            1,
            ReservationRequest::query()->where('restaurant_id', $restaurant->id)->count(),
            'Fixture-driven inbound reply must thread, not create a new reservation.',
        );
        $this->assertSame(
            1,
            ReservationMessage::query()
                ->where('reservation_request_id', $request->id)
                ->where('direction', MessageDirection::In)
                ->count(),
            'Fixture-driven reply must be appended as an inbound ReservationMessage.',
        );
        $this->assertDatabaseHas('reservation_messages', [
            'reservation_request_id' => $request->id,
            'direction' => MessageDirection::In->value,
            'message_id' => 'inbound-reply-irt-001@example.com',
        ]);
    }

    private function loadFixtureAsEmail(string $path): FetchedEmail
    {
        $this->assertFileExists($path, "Missing fixture: {$path}");

        $message = Message::fromFile($path);
        $from = $message->getFrom()[0] ?? null;

        return new FetchedEmail(
            messageId: trim((string) $message->getMessageId()),
            body: trim($message->hasTextBody() ? $message->getTextBody() : ''),
            senderEmail: $from?->mail ?? '',
            senderName: $from?->personal !== '' ? ($from?->personal ?? null) : null,
            rawHeaders: (string) $message->getHeader()->raw,
            rawBody: trim($message->hasTextBody() ? $message->getTextBody() : ''),
            inReplyTo: trim((string) $message->getInReplyTo()),
            references: trim((string) $message->getReferences()),
            subject: trim((string) $message->getSubject()),
            toAddress: 'mailbox@example.com',
        );
    }
}
