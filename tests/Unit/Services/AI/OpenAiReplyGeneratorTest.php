<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\OpenAiReplyGenerator;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\TestCase;

class OpenAiReplyGeneratorTest extends TestCase
{
    /**
     * Sentinel API key value used to verify the key never leaks into log
     * lines or exception messages, even on the error path.
     */
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
                'seats_free_at_desired' => 12,
                'alternative_slots' => [],
                'closed_reason' => null,
            ],
        ];
    }

    private function makeGenerator(ClientFake $fake): OpenAiReplyGenerator
    {
        return new OpenAiReplyGenerator($fake, new NullLogger);
    }

    public function test_it_returns_the_trimmed_assistant_content(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => "  Guten Tag Anna,\n\nwir haben um 19:30 einen Tisch frei.\n\nViele Grüße  ",
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ]),
        ]);

        $reply = $this->makeGenerator($fake)->generate($this->makeContext());

        $this->assertSame(
            "Guten Tag Anna,\n\nwir haben um 19:30 einen Tisch frei.\n\nViele Grüße",
            $reply
        );
    }

    public function test_it_sends_the_context_json_as_the_user_message_and_a_system_prompt(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        $context = $this->makeContext('formal');

        $this->makeGenerator($fake)->generate($context);

        $fake->assertSent(Chat::class, function (string $method, array $params) use ($context): bool {
            if ($method !== 'create') {
                return false;
            }

            $messages = $params['messages'] ?? [];
            if (count($messages) !== 2) {
                return false;
            }

            // System message MUST contain the formal tonality prompt and at least
            // one of the constant rules from config.
            $system = $messages[0]['content'] ?? '';
            $expectedTonality = config('reservations.ai.tonality_prompts.formal');
            if (! str_contains($system, $expectedTonality) || ! str_contains($system, 'Antworte ausschließlich auf Deutsch.')) {
                return false;
            }

            // User message MUST be the context JSON byte-for-byte.
            $expectedJson = json_encode($context, JSON_UNESCAPED_UNICODE);
            if (($messages[1]['content'] ?? null) !== $expectedJson) {
                return false;
            }

            return ($params['temperature'] ?? null) === 0.4;
        });
    }

    public function test_it_falls_back_when_the_response_content_is_empty(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => '   '], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        $reply = $this->makeGenerator($fake)->generate($this->makeContext());

        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $reply);
    }

    public function test_it_falls_back_when_the_client_throws(): void
    {
        $fake = OpenAI::fake([
            new RuntimeException('connection refused'),
        ]);

        $reply = $this->makeGenerator($fake)->generate($this->makeContext());

        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $reply);
    }

    public function test_the_api_key_never_appears_in_log_output(): void
    {
        config()->set('openai.api_key', self::LEAK_SENTINEL);

        $captured = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$captured): void {
            $captured[] = ['message' => $message, 'context' => $context];
        });
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();

        $fake = OpenAI::fake([
            new RuntimeException('boom — no key here either'),
        ]);

        $generator = new OpenAiReplyGenerator($fake, Log::getFacadeRoot());

        $generator->generate($this->makeContext());

        $serialized = json_encode($captured, JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($serialized);
        $this->assertStringNotContainsString(self::LEAK_SENTINEL, $serialized);
    }
}
