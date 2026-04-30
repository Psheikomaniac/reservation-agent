<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Enums\ReservationSource;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/analytics')->assertRedirect('/login');
    }

    public function test_it_renders_the_analytics_page_for_owner(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->count(3)
            ->create([
                'created_at' => Carbon::now()->subDays(2),
                'source' => ReservationSource::WebForm,
            ]);

        $this->actingAs($user)
            ->get('/analytics')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Analytics')
                    ->has('snapshot', fn (AssertableInertia $snapshot) => $snapshot
                        ->where('range', '30d')
                        ->where('totals.total', 3)
                        ->where('sources.web_form', 3)
                        ->etc()
                    )
            );
    }

    public function test_it_switches_range_via_query_string(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        // One request today, two requests 10 days ago.
        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['created_at' => Carbon::now()->subHours(2)]);
        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->count(2)
            ->create(['created_at' => Carbon::now()->subDays(10)]);

        $this->actingAs($user)
            ->get('/analytics?range=today')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Analytics')
                    ->where('snapshot.range', 'today')
                    ->where('snapshot.totals.total', 1)
                    ->etc()
            );
    }

    public function test_it_rejects_invalid_range_values(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($user)
            ->get('/analytics?range=all-time')
            ->assertSessionHasErrors('range');
    }

    public function test_it_forbids_access_for_users_without_restaurant(): void
    {
        $user = User::factory()->create(['restaurant_id' => null]);

        $this->actingAs($user)
            ->get('/analytics')
            ->assertNotFound();
    }

    public function test_it_scopes_data_to_the_users_restaurant(): void
    {
        $own = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($own)->create();

        ReservationRequest::factory()
            ->forRestaurant($own)
            ->create(['created_at' => Carbon::now()->subDays(1)]);
        ReservationRequest::factory()
            ->forRestaurant($other)
            ->count(5)
            ->create(['created_at' => Carbon::now()->subDays(1)]);

        $this->actingAs($user)
            ->get('/analytics')
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('snapshot.totals.total', 1)
                    ->etc()
            );
    }
}
