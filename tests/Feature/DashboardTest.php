<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_lands_on_dashboard_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'));
    }

    public function test_dashboard_receives_restaurant_name_via_shared_props(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Trattoria Testa']);
        $user = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('restaurant.id', $restaurant->id)
                ->where('restaurant.name', 'Trattoria Testa')
                ->where('restaurant.timezone', $restaurant->timezone)
                ->etc()
            );
    }

    public function test_user_without_restaurant_receives_null_restaurant_prop(): void
    {
        $user = User::factory()->create(['restaurant_id' => null]);

        $this->actingAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('restaurant', null)
            );
    }

    public function test_seeded_demo_owner_can_login_and_reach_dashboard_with_restaurant_name(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->post('/login', [
            'email' => 'owner@demo.test',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('restaurant.name', 'Demo Restaurant')
                ->etc()
            );
    }
}
