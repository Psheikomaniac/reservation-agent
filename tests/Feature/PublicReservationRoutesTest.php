<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PublicReservationRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_route_renders_form_for_known_slug(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Demo Restaurant',
            'slug' => 'demo',
        ]);

        $this->get(route('public.reservations.create', $restaurant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/ReservationForm')
                ->where('restaurant.name', 'Demo Restaurant')
                ->where('restaurant.slug', 'demo')
            );
    }

    public function test_create_route_returns_404_for_unknown_slug(): void
    {
        $this->get('/r/unknown-slug/reservations')->assertNotFound();
    }

    public function test_store_route_returns_404_for_unknown_slug(): void
    {
        $this->post('/r/unknown-slug/reservations')->assertNotFound();
    }

    public function test_thanks_route_renders_for_known_slug(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'Demo Restaurant',
            'slug' => 'demo',
        ]);

        $this->get(route('public.reservations.thanks', $restaurant))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Thanks', false)
                ->where('restaurant.name', 'Demo Restaurant')
            );
    }

    public function test_thanks_route_returns_404_for_unknown_slug(): void
    {
        $this->get('/r/unknown-slug/reservations/thanks')->assertNotFound();
    }

    public function test_store_route_throttles_at_eleventh_request_per_minute(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);
        $url = route('public.reservations.store', $restaurant);

        for ($i = 0; $i < 10; $i++) {
            $this->post($url)->assertRedirect();
        }

        $this->post($url)->assertStatus(429);
    }
}
