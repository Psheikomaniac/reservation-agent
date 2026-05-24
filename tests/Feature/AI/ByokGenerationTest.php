<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Restaurant;
use App\Services\AI\OpenAiClientFactory;
use App\Services\AI\OpenAiReplyGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class ByokGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_restaurant_with_its_own_key_uses_the_factory_client(): void
    {
        $restaurant = Restaurant::factory()->create(['openai_api_key' => 'sk-restaurant']);

        $globalFake = new ClientFake([
            CreateResponse::fake(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'GLOBAL'], 'finish_reason' => 'stop']]]),
        ]);
        $restaurantFake = new ClientFake([
            CreateResponse::fake(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'BYOK'], 'finish_reason' => 'stop']]]),
        ]);

        $factory = $this->createMock(OpenAiClientFactory::class);
        $factory->expects($this->once())
            ->method('clientFor')
            ->with($this->callback(fn (?Restaurant $r): bool => $r?->is($restaurant) === true))
            ->willReturn($restaurantFake);

        $generator = new OpenAiReplyGenerator($globalFake, new NullLogger, null, $factory);

        $this->assertSame('BYOK', $generator->generate($this->context(), $restaurant));
    }

    public function test_a_restaurant_without_its_own_key_uses_the_default_client(): void
    {
        $restaurant = Restaurant::factory()->create(['openai_api_key' => null]);

        $globalFake = new ClientFake([
            CreateResponse::fake(['choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'GLOBAL'], 'finish_reason' => 'stop']]]),
        ]);

        $factory = $this->createMock(OpenAiClientFactory::class);
        $factory->expects($this->never())->method('clientFor');

        $generator = new OpenAiReplyGenerator($globalFake, new NullLogger, null, $factory);

        $this->assertSame('GLOBAL', $generator->generate($this->context(), $restaurant));
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'restaurant' => ['name' => 'Bella', 'tonality' => 'formal'],
            'request' => ['guest_name' => 'Anna', 'party_size' => 2],
            'availability' => ['is_available' => true],
        ];
    }
}
