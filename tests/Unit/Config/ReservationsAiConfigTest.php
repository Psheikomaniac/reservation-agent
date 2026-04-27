<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Enums\Tonality;
use Tests\TestCase;

/**
 * Guards the AI block in config/reservations.php (Issue #64).
 *
 * Acts as the single source of truth for tonality prompts and the
 * constant system-prompt rules consumed by OpenAiReplyGenerator. If any
 * tonality enum case has no corresponding non-empty prompt the AI
 * generator would silently send an unguided system prompt to OpenAI.
 */
class ReservationsAiConfigTest extends TestCase
{
    public function test_openai_model_falls_back_to_gpt_4o_mini(): void
    {
        $this->assertSame('gpt-4o-mini', config('reservations.ai.openai_model'));
    }

    public function test_every_tonality_case_has_a_non_empty_prompt(): void
    {
        $prompts = config('reservations.ai.tonality_prompts');

        $this->assertIsArray($prompts);

        foreach (Tonality::cases() as $tonality) {
            $this->assertArrayHasKey(
                $tonality->value,
                $prompts,
                "Missing tonality prompt for '{$tonality->value}'."
            );

            $this->assertIsString($prompts[$tonality->value]);
            $this->assertNotSame('', trim($prompts[$tonality->value]));
        }
    }

    public function test_system_prompt_rules_are_a_non_empty_list_of_strings(): void
    {
        $rules = config('reservations.ai.system_prompt_rules');

        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);

        foreach ($rules as $rule) {
            $this->assertIsString($rule);
            $this->assertNotSame('', trim($rule));
        }
    }
}
