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
 * The system prompt sent to OpenAI must include the seven tonality-
 * independent rules from PRD-005 (issue #69) verbatim, in addition to the
 * per-restaurant tonality block. This test pins the rule text so any
 * accidental rewording in `config/reservations.php` fails CI.
 */
class OpenAiReplySystemPromptRulesTest extends TestCase
{
    /**
     * The verbatim rules required by PRD-005. If a future PR genuinely
     * changes these, both this fixture AND the config must move together
     * — the duplication is intentional and is the point of the test.
     *
     * @var list<string>
     */
    private const array REQUIRED_RULES = [
        'Antworte ausschließlich auf Deutsch.',
        'Verwende NUR die im User-JSON enthaltenen Zahlen und Zeiten. Erfinde keine eigenen.',
        'Wenn `is_open_at_desired_time` = false, biete höflich die `alternative_slots` an oder verweise auf `closed_reason`.',
        'Wenn `seats_free_at_desired` < `request.party_size`, lehne höflich ab und biete Alternativen an.',
        'Antworte in maximal 120 Wörtern.',
        'Keine Emojis, keine Hashtags, keine Marketing-Phrasen.',
        'Beginne mit Anrede („Guten Tag [Name],"), ende mit Grußformel und Restaurant-Name.',
    ];

    private function makeContext(string $tonality = 'casual'): array
    {
        return [
            'restaurant' => ['name' => 'Le Bistro', 'tonality' => $tonality],
            'request' => ['guest_name' => 'X', 'party_size' => 2, 'desired_at' => '2026-05-13 19:00', 'message' => null],
            'availability' => [
                'is_open_at_desired_time' => true,
                'seats_free_at_desired' => 10,
                'alternative_slots' => [],
                'closed_reason' => null,
            ],
        ];
    }

    public function test_every_required_rule_appears_verbatim_in_the_system_prompt(): void
    {
        $fake = OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                ],
            ]),
        ]);

        $generator = new OpenAiReplyGenerator($fake, new NullLogger);
        $generator->generate($this->makeContext('formal'));

        $captured = null;
        $fake->assertSent(Chat::class, function (string $method, array $params) use (&$captured): bool {
            if ($method !== 'create') {
                return false;
            }

            $captured = $params['messages'][0]['content'] ?? null;

            return is_string($captured);
        });

        foreach (self::REQUIRED_RULES as $rule) {
            $this->assertStringContainsString(
                $rule,
                (string) $captured,
                "Rule missing verbatim from system prompt: {$rule}"
            );
        }
    }

    public function test_rules_are_appended_to_every_tonality(): void
    {
        foreach (['formal', 'casual', 'family'] as $tonality) {
            $fake = OpenAI::fake([
                CreateResponse::fake([
                    'choices' => [
                        ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop'],
                    ],
                ]),
            ]);

            $generator = new OpenAiReplyGenerator($fake, new NullLogger);
            $generator->generate($this->makeContext($tonality));

            $tonalityPrompt = config("reservations.ai.tonality_prompts.{$tonality}");
            $this->assertIsString($tonalityPrompt);

            $captured = null;
            $fake->assertSent(Chat::class, function (string $method, array $params) use (&$captured): bool {
                $captured = $params['messages'][0]['content'] ?? null;

                return $method === 'create';
            });

            // Tonality block AND every rule must coexist in the same prompt.
            $this->assertStringContainsString($tonalityPrompt, (string) $captured);
            foreach (self::REQUIRED_RULES as $rule) {
                $this->assertStringContainsString($rule, (string) $captured);
            }
        }
    }
}
