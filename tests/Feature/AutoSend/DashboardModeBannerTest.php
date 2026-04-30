<?php

declare(strict_types=1);

namespace Tests\Feature\AutoSend;

use App\Enums\SendMode;
use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardModeBannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_send_mode_on_dashboard_when_not_manual(): void
    {
        $restaurant = Restaurant::factory()->create();
        $restaurant->forceFill(['send_mode' => SendMode::Auto])->save();

        $owner = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => UserRole::Owner,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('sendMode', SendMode::Auto->value)
            );
    }

    public function test_it_passes_manual_send_mode_in_v1_default(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->create([
            'restaurant_id' => $restaurant->id,
            'role' => UserRole::Owner,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sendMode', SendMode::Manual->value)
            );
    }
}
