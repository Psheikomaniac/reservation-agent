<?php

namespace Tests\Feature;

use App\Enums\ReservationSource;
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

    public function test_show_response_carries_detail_resource_shape(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $reservation = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create([
                'source' => ReservationSource::Email,
                'message' => 'Allergie: Nüsse',
                'raw_payload' => [
                    'body' => "Hallo,\nbitte für 4 Personen am Freitag.",
                    'sender_email' => 'guest@example.test',
                    'sender_name' => 'Anna Probe',
                    'message_id' => '<abc@example.test>',
                ],
            ]);

        $this->actingAs($user);

        $this->get("/reservations/{$reservation->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reservations/Show')
                ->where('reservation.id', $reservation->id)
                ->where('reservation.message', 'Allergie: Nüsse')
                ->where('reservation.has_raw_email', true)
                ->where('reservation.raw_email_body', "Hallo,\nbitte für 4 Personen am Freitag.")
                ->has('reservation.raw_payload')
                ->where('reservation.raw_payload.sender_email', 'guest@example.test')
            );
    }

    public function test_web_form_source_does_not_leak_a_raw_email_body(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $reservation = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create([
                'source' => ReservationSource::WebForm,
                'raw_payload' => ['guest_name' => 'Anna Probe', 'party_size' => 4],
            ]);

        $this->actingAs($user);

        $this->get("/reservations/{$reservation->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reservation.has_raw_email', false)
                ->where('reservation.raw_email_body', null)
                ->where('reservation.raw_payload.guest_name', 'Anna Probe')
            );
    }
}
