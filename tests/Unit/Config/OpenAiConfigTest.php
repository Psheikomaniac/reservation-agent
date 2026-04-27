<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use OpenAI\Client;
use OpenAI\Contracts\ClientContract;
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
    public function test_openai_service_provider_binds_the_client_in_the_container(): void
    {
        $this->assertTrue($this->app->bound(Client::class));
        $this->assertTrue($this->app->bound(ClientContract::class));
    }

    public function test_openai_config_reads_api_key_from_env_via_env_helper(): void
    {
        $sentinel = 'sk-test-config-wiring-'.bin2hex(random_bytes(4));
        $original = getenv('OPENAI_API_KEY');

        putenv("OPENAI_API_KEY={$sentinel}");

        try {
            $config = require base_path('config/openai.php');

            $this->assertArrayHasKey('api_key', $config);
            $this->assertSame($sentinel, $config['api_key']);
        } finally {
            putenv($original === false ? 'OPENAI_API_KEY' : "OPENAI_API_KEY={$original}");
        }
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
