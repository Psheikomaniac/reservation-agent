<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Restaurant;
use App\Services\AI\OpenAiClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Contracts\ClientContract;
use Tests\TestCase;

final class OpenAiClientFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_the_per_restaurant_key_with_global_fallback(): void
    {
        config(['openai.api_key' => 'sk-global']);
        $factory = $this->app->make(OpenAiClientFactory::class);

        $withKey = Restaurant::factory()->create(['openai_api_key' => 'sk-restaurant']);
        $withoutKey = Restaurant::factory()->create(['openai_api_key' => null]);

        $this->assertSame('sk-restaurant', $factory->resolveKey($withKey));
        $this->assertSame('sk-global', $factory->resolveKey($withoutKey));
        $this->assertSame('sk-global', $factory->resolveKey(null));
    }

    public function test_it_builds_a_usable_client(): void
    {
        config(['openai.api_key' => 'sk-global']);
        $factory = $this->app->make(OpenAiClientFactory::class);

        $this->assertInstanceOf(
            ClientContract::class,
            $factory->clientFor(Restaurant::factory()->create(['openai_api_key' => 'sk-restaurant'])),
        );
        $this->assertInstanceOf(ClientContract::class, $factory->clientFor(null, 5));
    }
}
