<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Exceptions\AI\OpenAiTimeoutException;
use App\Services\AI\OpenAiReplyGenerator;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the synchronous reply path used by the PRD-014 web sync-confirm
 * flow. The submit path can only confirm with a *real* AI reply, so
 * `generateSync` throws on every failure (timeout, empty completion)
 * instead of returning the neutral fallback the async `generate` uses.
 */
class OpenAiReplyGeneratorGenerateSyncTest extends TestCase
{
    private const string LEAK_SENTINEL = 'sk-test-LEAK-CHECK-12345';

    private function makeContext(string $tonality = 'casual'): array
    {
        return [
            'restaurant' => [
                'name' => 'La Trattoria',
                'tonality' => $tonality,
            ],
            'request' => [
                'guest_name' => 'Anna Müller',
                'party_size' => 4,
                'desired_at' => '2026-05-13 19:30',
                'message' => 'Möglichst Fensterplatz, danke.',
            ],
            'availability' => [
                'is_open_at_desired_time' => true,
                'closed_reason' => null,
                'slot_state' => 'free',
                'is_available' => true,
                'alternative_slots' => [],
            ],
        ];
    }

    private function makeGenerator(ClientFake $fake): OpenAiReplyGenerator
    {
        return new OpenAiReplyGenerator($fake, new NullLogger);
    }

    private function timeoutException(): TransporterException
    {
        // Mirrors what openai-php raises in production: a Guzzle connection
        // timeout (ClientExceptionInterface) wrapped into TransporterException.
        return new TransporterException(
            new ConnectException(
                'cURL error 28: Operation timed out after 5000 milliseconds',
                new Request('POST', 'https://api.openai.com/v1/chat/completions'),
            ),
        );
    }

    public function test_it_generates_a_reply_synchronously(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => "  Guten Tag Anna,\n\nIhr Tisch um 19:30 ist bestätigt.\n\nViele Grüße  ",
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]),
        ]);

        $reply = $this->makeGenerator($fake)->generateSync($this->makeContext());

        $this->assertSame(
            "Guten Tag Anna,\n\nIhr Tisch um 19:30 ist bestätigt.\n\nViele Grüße",
            $reply
        );
    }

    public function test_it_throws_openai_timeout_exception_on_timeout(): void
    {
        $fake = OpenAI::fake([
            $this->timeoutException(),
        ]);

        $this->expectException(OpenAiTimeoutException::class);

        $this->makeGenerator($fake)->generateSync($this->makeContext());
    }

    public function test_it_throws_instead_of_returning_the_fallback_when_completion_is_empty(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => '   '], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        // A blank completion must never be sent as a confirmation; the caller
        // falls back to the V1 path instead.
        $this->expectException(RuntimeException::class);

        $this->makeGenerator($fake)->generateSync($this->makeContext());
    }

    public function test_it_passes_the_timeout_to_the_sync_client_factory(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        $capturedTimeout = null;
        $factory = function (int $timeout) use ($fake, &$capturedTimeout): ClientContract {
            $capturedTimeout = $timeout;

            return $fake;
        };

        $generator = new OpenAiReplyGenerator($fake, new NullLogger, $factory);

        $generator->generateSync($this->makeContext(), timeout: 3);

        $this->assertSame(3, $capturedTimeout);
    }

    public function test_it_defaults_to_a_five_second_timeout(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        $capturedTimeout = null;
        $factory = function (int $timeout) use ($fake, &$capturedTimeout): ClientContract {
            $capturedTimeout = $timeout;

            return $fake;
        };

        (new OpenAiReplyGenerator($fake, new NullLogger, $factory))->generateSync($this->makeContext());

        $this->assertSame(5, $capturedTimeout);
    }

    public function test_the_api_key_never_appears_in_logs_or_the_thrown_exception(): void
    {
        config()->set('openai.api_key', self::LEAK_SENTINEL);

        $captured = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$captured): void {
            $captured[] = ['message' => $message, 'context' => $context];
        });
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();

        $fake = OpenAI::fake([
            $this->timeoutException(),
        ]);

        $generator = new OpenAiReplyGenerator($fake, Log::getFacadeRoot());

        $thrownMessage = '';
        try {
            $generator->generateSync($this->makeContext());
        } catch (OpenAiTimeoutException $e) {
            $thrownMessage = $e->getMessage();
        }

        $serialized = json_encode($captured, JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($serialized);
        $this->assertStringNotContainsString(self::LEAK_SENTINEL, $serialized);
        $this->assertStringNotContainsString(self::LEAK_SENTINEL, $thrownMessage);
    }
}
