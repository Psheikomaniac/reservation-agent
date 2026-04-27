<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

/**
 * Smoke test for the openai-php/laravel install (Issue #63).
 *
 * Guards three guarantees that the rest of PRD-005 relies on:
 *   1. `config/openai.php` reads `OPENAI_API_KEY` from the env.
 *   2. The `OpenAI` facade is registered and resolvable.
 *   3. `OpenAI::fake([...])` returns canned responses without any network call.
 */
class OpenAiConfigTest extends TestCase
{
    public function test_openai_config_reads_api_key_from_env(): void
    {
        $key = 'sk-config-test-only';

        config()->set('openai.api_key', null);
        putenv("OPENAI_API_KEY={$key}");

        $config = include __DIR__.'/../../../config/openai.php';

        $this->assertSame($key, $config['api_key']);

        putenv('OPENAI_API_KEY');
    }

    public function test_openai_facade_returns_faked_chat_response_without_network(): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => ['role' => 'assistant', 'content' => 'fake-pong'],
                    ],
                ],
            ]),
        ]);

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]);

        $this->assertSame('fake-pong', $response->choices[0]->message->content);
    }
}
