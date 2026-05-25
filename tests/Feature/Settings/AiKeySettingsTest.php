<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class AiKeySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_masked_key_and_can_update_it(): void
    {
        $restaurant = Restaurant::factory()->create(['openai_api_key' => 'sk-old-1234']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->get(route('settings.ai-key.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('settings/AiKey')
                ->where('configured', true)
                ->where('masked', '••••1234')
                ->missing('openai_api_key'));

        $this->actingAs($owner)->patch(route('settings.ai-key.update'), ['openai_api_key' => 'sk-new-5678'])
            ->assertRedirect();

        $this->assertSame('sk-new-5678', $restaurant->fresh()->openai_api_key);
    }

    public function test_empty_submission_keeps_the_existing_key(): void
    {
        $restaurant = Restaurant::factory()->create(['openai_api_key' => 'sk-keep-9999']);
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->patch(route('settings.ai-key.update'), ['openai_api_key' => ''])->assertRedirect();

        $this->assertSame('sk-keep-9999', $restaurant->fresh()->openai_api_key);
    }

    public function test_invalid_key_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)
            ->from(route('settings.ai-key.edit'))
            ->patch(route('settings.ai-key.update'), ['openai_api_key' => 'not-a-key'])
            ->assertSessionHasErrors('openai_api_key');
    }

    public function test_staff_cannot_access(): void
    {
        $restaurant = Restaurant::factory()->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($staff)->get(route('settings.ai-key.edit'))->assertForbidden();
        $this->actingAs($staff)->patch(route('settings.ai-key.update'), ['openai_api_key' => 'sk-x-1234'])->assertForbidden();
    }
}
