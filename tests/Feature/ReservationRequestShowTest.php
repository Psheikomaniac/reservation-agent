<?php

namespace Tests\Feature;

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
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

    public function test_member_of_restaurant_sees_own_reservation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $reservation = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['guest_name' => 'Alice Example']);

        $this->actingAs($user);

        $this->get("/reservations/{$reservation->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Show')
                ->where('reservation.id', $reservation->id)
                ->where('reservation.guest_name', 'Alice Example')
                ->where('reservation.status', $reservation->status->value)
                ->where('reservation.source', $reservation->source->value)
                ->etc()
            );
    }

    public function test_foreign_tenant_reservation_returns_403_not_404(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();
        $foreignReservation = ReservationRequest::factory()
            ->forRestaurant($restaurantB)
            ->create();

        $this->actingAs($userA);

        $this->get("/reservations/{$foreignReservation->id}")
            ->assertForbidden();
    }

    public function test_unknown_id_returns_404(): void
    {
        $user = User::factory()->forRestaurant(Restaurant::factory()->create())->create();

        $this->actingAs($user);

        $this->get('/reservations/999999')
            ->assertNotFound();
    }

    public function test_user_without_restaurant_cannot_view_any_reservation(): void
    {
        $reservation = ReservationRequest::factory()
            ->forRestaurant(Restaurant::factory()->create())
            ->create();
        $orphan = User::factory()->create(['restaurant_id' => null]);

        $this->actingAs($orphan);

        $this->get("/reservations/{$reservation->id}")
            ->assertForbidden();
    }
}
