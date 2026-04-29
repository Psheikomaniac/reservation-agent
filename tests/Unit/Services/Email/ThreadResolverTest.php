<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Models\ReservationMessage;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Email\ThreadResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Message;

class ThreadResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_returns_null_when_no_strategy_matches(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('warning');

        $resolver = new ThreadResolver($logger);

        $this->assertNull($resolver->resolveForIncoming($this->makeMessage('attacker@example.com'), 1));
    }

    public function test_it_rejects_spoofed_sender_even_when_a_strategy_matches(): void
    {
        $request = new ReservationRequest;
        $request->id = 42;
        $request->guest_email = 'guest@example.com';

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();

        $resolver = $this->makeResolverWithStrategyHit($logger, $request);

        $this->assertNull(
            $resolver->resolveForIncoming($this->makeMessage('attacker@example.com', '<spoof@x>'), 1),
            'Spoofed sender must not yield a thread match.',
        );
    }

    public function test_it_does_not_log_guest_email_or_sender_address_on_mismatch(): void
    {
        $request = new ReservationRequest;
        $request->id = 7;
        $request->guest_email = 'guest@example.com';

        $captured = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->andReturnUsing(function (string $message, array $context) use (&$captured): void {
                $captured = ['message' => $message, 'context' => $context];
            });

        $resolver = $this->makeResolverWithStrategyHit($logger, $request);

        $resolver->resolveForIncoming($this->makeMessage('attacker@example.com', '<m@x>'), 1);

        $serialized = $captured['message'].json_encode($captured['context']);

        $this->assertStringNotContainsString('guest@example.com', $serialized);
        $this->assertStringNotContainsString('attacker@example.com', $serialized);
    }

    public function test_it_returns_the_request_when_sender_matches(): void
    {
        $request = new ReservationRequest;
        $request->id = 1;
        $request->guest_email = 'Guest@Example.com';

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('warning');

        $resolver = $this->makeResolverWithStrategyHit($logger, $request);

        $resolved = $resolver->resolveForIncoming($this->makeMessage(' guest@example.com '), 1);

        $this->assertSame($request, $resolved);
    }

    private function makeResolverWithStrategyHit(LoggerInterface $logger, ReservationRequest $hit): ThreadResolver
    {
        return new class($logger, $hit) extends ThreadResolver
        {
            public function __construct(LoggerInterface $logger, private readonly ReservationRequest $hit)
            {
                parent::__construct($logger);
            }

            protected function byInReplyTo(Message $message, int $restaurantId): ?ReservationRequest
            {
                return $this->hit;
            }
        };
    }

    public function test_it_resolves_by_in_reply_to_header(): void
    {
        [$request, $outboundId] = $this->seedOutboundThread('guest@example.com');

        $resolver = new ThreadResolver(new NullLogger);

        $resolved = $resolver->resolveForIncoming(
            $this->makeMessage('guest@example.com', inReplyTo: $outboundId),
            $request->restaurant_id,
        );

        $this->assertNotNull($resolved);
        $this->assertSame($request->id, $resolved->id);
    }

    public function test_it_resolves_by_references_chain_when_in_reply_to_is_unknown(): void
    {
        [$request, $outboundId] = $this->seedOutboundThread('guest@example.com');

        $resolver = new ThreadResolver(new NullLogger);

        $resolved = $resolver->resolveForIncoming(
            $this->makeMessage(
                'guest@example.com',
                inReplyTo: '<unknown@x>',
                references: '<root@x> '.$outboundId.' <other@x>',
            ),
            $request->restaurant_id,
        );

        $this->assertNotNull($resolved);
        $this->assertSame($request->id, $resolved->id);
    }

    public function test_it_does_not_match_across_restaurants(): void
    {
        [$request, $outboundId] = $this->seedOutboundThread('guest@example.com');

        $otherRestaurant = Restaurant::factory()->create();

        $resolver = new ThreadResolver(new NullLogger);

        $resolved = $resolver->resolveForIncoming(
            $this->makeMessage('guest@example.com', inReplyTo: $outboundId),
            $otherRestaurant->id,
        );

        $this->assertNull(
            $resolved,
            'An outbound message belonging to restaurant A must not resolve when the inbound mail targets restaurant B.',
        );
        $this->assertNotSame($request->restaurant_id, $otherRestaurant->id);
    }

    public function test_it_rejects_spoofed_in_reply_to_when_sender_does_not_match(): void
    {
        [$request, $outboundId] = $this->seedOutboundThread('guest@example.com');

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();

        $resolver = new ThreadResolver($logger);

        $resolved = $resolver->resolveForIncoming(
            $this->makeMessage('attacker@example.com', inReplyTo: $outboundId),
            $request->restaurant_id,
        );

        $this->assertNull($resolved, 'A spoofed In-Reply-To must not yield a thread match when the sender differs.');
    }

    /**
     * @return array{0: ReservationRequest, 1: string} the request and the outbound message-id
     */
    private function seedOutboundThread(string $guestEmail): array
    {
        $restaurant = Restaurant::factory()->create();
        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'guest_email' => $guestEmail,
        ]);

        $outbound = ReservationMessage::factory()
            ->outbound()
            ->forReservationRequest($request)
            ->create();

        return [$request, $outbound->message_id];
    }

    /**
     * Builds a Mockery double of Webklex Message with a single From address,
     * Message-ID, In-Reply-To and References headers. Defaults keep the older
     * tests stable: when In-Reply-To/References are empty strings the new
     * strategies short-circuit just like before.
     */
    private function makeMessage(
        string $fromMail,
        string $messageId = '<test@example.com>',
        string $inReplyTo = '',
        string $references = '',
    ): Message&MockInterface {
        $address = Mockery::mock(Address::class);
        $address->mail = $fromMail;

        $message = Mockery::mock(Message::class);
        $message->shouldReceive('getFrom')->andReturn([$address]);
        $message->shouldReceive('getMessageId')->andReturn($messageId);
        $message->shouldReceive('getInReplyTo')->andReturn($inReplyTo);
        $message->shouldReceive('getReferences')->andReturn($references);

        return $message;
    }
}
