<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Models\ReservationRequest;
use App\Services\Email\ThreadResolver;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Message;

class ThreadResolverTest extends TestCase
{
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

    /**
     * Builds a Mockery double of Webklex Message with a single From address
     * and a Message-ID. The Address class has a public `mail` property, so
     * a stdClass-equivalent stub via Mockery is enough — the resolver only
     * reads `getFrom()[0]->mail` and `getMessageId()`.
     */
    private function makeMessage(string $fromMail, string $messageId = '<test@example.com>'): Message&MockInterface
    {
        $address = Mockery::mock(Address::class);
        $address->mail = $fromMail;

        $message = Mockery::mock(Message::class);
        $message->shouldReceive('getFrom')->andReturn([$address]);
        $message->shouldReceive('getMessageId')->andReturn($messageId);

        return $message;
    }
}
