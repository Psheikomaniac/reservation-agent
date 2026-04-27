<?php

namespace Tests\Feature;

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationRequestShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $reservation = ReservationRequest::factory()
            ->forRestaurant(Restaurant::factory()->create())
            ->create();

        $this->get("/reservations/{$reservation->id}")
            ->assertRedirect('/login');
    }

    public function test_authenticated_request_redirects_into_dashboard_drawer(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $reservation = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create();

        $this->actingAs($user)
            ->get("/reservations/{$reservation->id}")
            ->assertRedirect("/dashboard?selected={$reservation->id}");
    }

    public function test_redirect_does_not_leak_existence_for_foreign_tenant(): void
    {
        // Authorization is enforced at the dashboard level (selectedRequest
        // resolves to null for foreign tenants); the redirect itself is
        // unconditional, which is acceptable since the id is already known
        // to the caller.
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();
        $foreignReservation = ReservationRequest::factory()
            ->forRestaurant($restaurantB)
            ->create();

        $this->actingAs($userA)
            ->get("/reservations/{$foreignReservation->id}")
            ->assertRedirect("/dashboard?selected={$foreignReservation->id}");
    }

    public function test_unknown_id_still_redirects_drawer_handles_null(): void
    {
        $user = User::factory()->forRestaurant(Restaurant::factory()->create())->create();

        $this->actingAs($user)
            ->get('/reservations/999999')
            ->assertRedirect('/dashboard?selected=999999');
    }
}
