<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Models\ReservationMessage;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Email\DTO\FetchedEmail;
use App\Services\Email\ThreadResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * Fixture-driven counterpart to ThreadResolverTest. Loads real RFC-2822 .eml
 * files from tests/Fixtures/emails/threading/ via Webklex's Message::fromFile,
 * lifts them into the FetchedEmail DTO the resolver consumes (matching the
 * conversion done by WebklexImapMailbox in production), and runs the same
 * cascade against a per-test factory seed.
 */
class ThreadResolverFixtureTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_DIR = __DIR__.'/../../../Fixtures/emails/threading';

    // Stored without angle brackets — matches the convention used everywhere
    // else in the codebase (WebklexImapMailbox writes the bare id from the
    // Webklex Message API, and SendReservationReplyJob does the same).
    private const KNOWN_OUTBOUND_ID = 'outbound-thread-id-001@restaurant.example';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_resolves_by_in_reply_to_header(): void
    {
        $request = $this->seedReservationWithOutboundMessage();

        $resolved = $this->resolver()
            ->resolveForIncoming($this->loadFixtureAsEmail('reply-with-in-reply-to.eml'), $request->restaurant_id);

        $this->assertNotNull($resolved);
        $this->assertSame($request->id, $resolved->id);
    }

    public function test_it_resolves_by_references_chain(): void
    {
        $request = $this->seedReservationWithOutboundMessage();

        $resolved = $this->resolver()
            ->resolveForIncoming($this->loadFixtureAsEmail('reply-with-references-only.eml'), $request->restaurant_id);

        $this->assertNotNull($resolved);
        $this->assertSame($request->id, $resolved->id);
    }

    public function test_it_resolves_by_subject_marker(): void
    {
        $request = $this->seedReservationWithOutboundMessage();

        $resolved = $this->resolver()
            ->resolveForIncoming($this->loadFixtureAsEmail('reply-with-subject-marker-only.eml'), $request->restaurant_id);

        $this->assertNotNull($resolved);
        $this->assertSame($request->id, $resolved->id);
    }

    public function test_it_resolves_by_heuristic_when_within_30_days(): void
    {
        $request = $this->seedReservationWithOutboundMessage();
        ReservationMessage::factory()->outbound()->forReservationRequest($request)->create([
            'subject' => 'Reservierung am 12.05.',
            'sent_at' => now()->subDays(3),
        ]);

        $resolved = $this->resolver()
            ->resolveForIncoming($this->loadFixtureAsEmail('reply-heuristic-match.eml'), $request->restaurant_id);

        $this->assertNotNull($resolved);
        $this->assertSame($request->id, $resolved->id);
    }

    public function test_it_does_not_resolve_by_heuristic_when_older_than_30_days(): void
    {
        $request = $this->seedReservationWithOutboundMessage();
        ReservationMessage::factory()->outbound()->forReservationRequest($request)->create([
            'subject' => 'Reservierung am 12.05.',
            'sent_at' => now()->subDays(45),
        ]);

        $resolved = $this->resolver()
            ->resolveForIncoming($this->loadFixtureAsEmail('reply-heuristic-match.eml'), $request->restaurant_id);

        $this->assertNull(
            $resolved,
            'Heuristic must not match an outbound mail older than the 30-day window.',
        );
    }

    public function test_it_rejects_spoofed_in_reply_to_when_sender_does_not_match(): void
    {
        $request = $this->seedReservationWithOutboundMessage();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();

        $resolver = new ThreadResolver($logger);

        $resolved = $resolver->resolveForIncoming(
            $this->loadFixtureAsEmail('reply-spoofed-in-reply-to.eml'),
            $request->restaurant_id,
        );

        $this->assertNull($resolved, 'A spoofed In-Reply-To from the wrong sender must not yield a thread match.');
    }

    public function test_it_returns_null_when_no_strategy_matches(): void
    {
        $request = $this->seedReservationWithOutboundMessage();

        $resolved = $this->resolver()
            ->resolveForIncoming($this->loadFixtureAsEmail('reply-no-match.eml'), $request->restaurant_id);

        $this->assertNull($resolved);
    }

    public function test_it_does_not_match_across_restaurants(): void
    {
        $request = $this->seedReservationWithOutboundMessage();
        $otherRestaurant = Restaurant::factory()->create();

        $resolved = $this->resolver()
            ->resolveForIncoming($this->loadFixtureAsEmail('reply-with-in-reply-to.eml'), $otherRestaurant->id);

        $this->assertNull(
            $resolved,
            'A thread match against restaurant A must not resolve when the inbound mail targets restaurant B.',
        );
    }

    private function resolver(): ThreadResolver
    {
        return new ThreadResolver(new NullLogger);
    }

    private function seedReservationWithOutboundMessage(): ReservationRequest
    {
        $restaurant = Restaurant::factory()->create();
        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'guest_email' => 'anna.mueller@example.com',
            'id' => 1,
        ]);

        ReservationMessage::factory()
            ->outbound()
            ->forReservationRequest($request)
            ->create([
                'message_id' => self::KNOWN_OUTBOUND_ID,
                'sent_at' => now()->subHour(),
            ]);

        return $request;
    }

    private function loadFixtureAsEmail(string $filename): FetchedEmail
    {
        $path = self::FIXTURE_DIR.'/'.$filename;

        $this->assertFileExists($path, "Missing fixture: {$filename}");

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
            toAddress: '',
        );
    }
}
