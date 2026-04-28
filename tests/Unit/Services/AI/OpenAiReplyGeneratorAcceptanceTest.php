<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\OpenAiReplyGenerator;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * One test per PRD-005 acceptance bullet for `OpenAiReplyGenerator`
 * (issue #78). Companion files exercise each axis in depth:
 *
 *   - prompt assembly: `OpenAiReplySystemPromptRulesTest`
 *   - error classification: `OpenAiReplyGeneratorErrorClassificationTest`
 *   - core behaviour: `OpenAiReplyGeneratorTest`
 *
 * This file is the AC checklist: if a future change accidentally drops
 * a documented behaviour, the matching case here fails first.
 */
class OpenAiReplyGeneratorAcceptanceTest extends TestCase
{
    private const string LEAK_SENTINEL = 'sk-test-LEAK-CHECK-12345';

    private function makeContext(): array
    {
        return [
            'restaurant' => ['name' => 'Le Bistro', 'tonality' => 'casual'],
            'request' => ['guest_name' => 'Anna', 'party_size' => 2, 'desired_at' => '2026-05-13 19:00', 'message' => null],
            'availability' => [
                'is_open_at_desired_time' => true,
                'seats_free_at_desired' => 12,
                'alternative_slots' => [],
                'closed_reason' => null,
            ],
        ];
    }

    /** AC 1: Builds the message payload correctly (system + user + JSON). */
    public function test_payload_has_system_plus_user_with_context_json_and_correct_temperature(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        $context = $this->makeContext();
        (new OpenAiReplyGenerator($fake, new NullLogger))->generate($context);

        $fake->assertSent(Chat::class, function (string $method, array $params) use ($context): bool {
            if ($method !== 'create') {
                return false;
            }

            $messages = $params['messages'] ?? [];

            return count($messages) === 2
                && ($messages[0]['role'] ?? null) === 'system'
                && ($messages[1]['role'] ?? null) === 'user'
                && ($messages[1]['content'] ?? null) === json_encode($context, JSON_UNESCAPED_UNICODE)
                && ($params['temperature'] ?? null) === 0.4;
        });
    }

    /** AC 2: Trims the returned content. */
    public function test_response_content_is_trimmed(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => "  \nGuten Tag, gerne!\n  "], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        $reply = (new OpenAiReplyGenerator($fake, new NullLogger))->generate($this->makeContext());

        $this->assertSame('Guten Tag, gerne!', $reply);
    }

    /** AC 3: Throwable path returns the fallback string. */
    public function test_unclassified_throwable_returns_fallback_string(): void
    {
        $fake = OpenAI::fake([
            new \RuntimeException('upstream timeout'),
        ]);

        $reply = (new OpenAiReplyGenerator($fake, new NullLogger))->generate($this->makeContext());

        $this->assertSame(OpenAiReplyGenerator::FALLBACK_TEXT, $reply);
    }

    /** AC 4: Sentinel API key never appears in captured logs or exceptions. */
    public function test_sentinel_api_key_never_appears_in_log_output(): void
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
            new \RuntimeException('boom — no key here either'),
        ]);

        (new OpenAiReplyGenerator($fake, $logger))->generate($this->makeContext());

        $serialized = (string) json_encode($captured, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::LEAK_SENTINEL, $serialized);
    }

    /** AC 5: No real network calls — exercised by using OpenAI::fake() throughout. */
    public function test_uses_only_the_faked_client_no_real_network(): void
    {
        // OpenAI::fake() swaps the facade with an in-memory ClientFake.
        // Any test in this file that constructs the generator with this fake
        // therefore cannot reach the network. This case keeps the AC explicit
        // by verifying assertSent fires (i.e. the call landed on the fake).
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        (new OpenAiReplyGenerator($fake, new NullLogger))->generate($this->makeContext());

        $fake->assertSent(Chat::class, fn (string $method): bool => $method === 'create');
    }
}
