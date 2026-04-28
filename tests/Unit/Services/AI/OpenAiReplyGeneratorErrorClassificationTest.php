<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Exceptions\AI\OpenAiAuthenticationException;
use App\Exceptions\AI\OpenAiRateLimitException;
use App\Services\AI\OpenAiReplyGenerator;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Laravel\Facades\OpenAI;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * The generator must classify the OpenAI exception types so the job
 * layer can drive the PRD-005 error matrix:
 *   - 401 ErrorException  → OpenAiAuthenticationException (rethrown)
 *   - RateLimitException  → OpenAiRateLimitException (rethrown)
 *   - ServerException     → fallback (handled in-place)
 *   - Anything else       → fallback (handled in-place)
 *
 * The sentinel-key leak guard is also exercised here on the throw paths
 * so a future change cannot start logging the API key alongside the
 * status code.
 */
class OpenAiReplyGeneratorErrorClassificationTest extends TestCase
{
    private const string LEAK_SENTINEL = 'sk-test-LEAK-CHECK-12345';

    private function makeContext(): array
    {
        return [
            'restaurant' => ['name' => 'X', 'tonality' => 'casual'],
            'request' => ['guest_name' => 'X', 'party_size' => 2, 'desired_at' => '2026-05-13 19:00', 'message' => null],
            'availability' => [
                'is_open_at_desired_time' => true,
                'seats_free_at_desired' => 10,
                'alternative_slots' => [],
                'closed_reason' => null,
            ],
        ];
    }

    public function test_401_response_is_classified_as_authentication_exception(): void
    {
        $fake = OpenAI::fake([
            new ErrorException(
                ['message' => 'invalid api key', 'type' => 'invalid_request_error'],
                new Response(401),
            ),
        ]);

        $generator = new OpenAiReplyGenerator($fake, new NullLogger);

        $this->expectException(OpenAiAuthenticationException::class);
        $generator->generate($this->makeContext());
    }

    public function test_rate_limit_response_is_classified_as_rate_limit_exception(): void
    {
        $fake = OpenAI::fake([
            new RateLimitException(new Response(429)),
        ]);

        $generator = new OpenAiReplyGenerator($fake, new NullLogger);

        $this->expectException(OpenAiRateLimitException::class);
        $generator->generate($this->makeContext());
    }

    public function test_500_server_error_falls_back_silently(): void
    {
        $fake = OpenAI::fake([
            new ServerException(new Response(500)),
        ]);

        $generator = new OpenAiReplyGenerator($fake, new NullLogger);

        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $generator->generate($this->makeContext()));
    }

    public function test_non_401_error_response_falls_back_silently(): void
    {
        $fake = OpenAI::fake([
            new ErrorException(
                ['message' => 'bad request', 'type' => 'invalid_request_error'],
                new Response(400),
            ),
        ]);

        $generator = new OpenAiReplyGenerator($fake, new NullLogger);

        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $generator->generate($this->makeContext()));
    }

    public function test_api_key_does_not_leak_when_classified_exceptions_are_thrown(): void
    {
        config()->set('openai.api_key', self::LEAK_SENTINEL);

        $captured = [];
        $logger = new class($captured) extends NullLogger
        {
            public function __construct(private array &$captured) {}

            public function warning($message, array $context = []): void
            {
                $this->captured[] = ['msg' => $message, 'ctx' => $context];
            }
        };

        $fake = OpenAI::fake([
            new ErrorException(
                ['message' => 'unauthorised'],
                new Response(401),
            ),
        ]);

        try {
            (new OpenAiReplyGenerator($fake, $logger))->generate($this->makeContext());
        } catch (OpenAiAuthenticationException) {
            // expected
        }

        $serialized = (string) json_encode($captured, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::LEAK_SENTINEL, $serialized);
    }
}
