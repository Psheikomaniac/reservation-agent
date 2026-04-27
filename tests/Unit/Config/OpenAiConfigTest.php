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

    public function test_openai_config_reads_api_key_from_the_correct_env_var(): void
    {
        // Static check: the published config must read api_key from env('OPENAI_API_KEY').
        // A runtime check is unreliable here because Laravel's env repository is
        // immutable after boot, so putenv()/$_ENV mutations don't propagate to env().
        // This guards against typos like env('OPENAI_KEY'), hard-coded values, or
        // removal of the env() call.
        $contents = file_get_contents(base_path('config/openai.php'));

        $this->assertMatchesRegularExpression(
            "/'api_key'\\s*=>\\s*env\\(\\s*'OPENAI_API_KEY'\\s*\\)/",
            $contents,
            "config/openai.php must read 'api_key' from env('OPENAI_API_KEY')."
        );
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
