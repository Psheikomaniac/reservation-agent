<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class QuickReservationCreateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Restaurant, 1: User}
     */
    private function ownerWithRestaurant(): array
    {
        // Default factory: Europe/Berlin, Monday open 11:30–14:30 + 18:00–22:30.
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->forRestaurant($restaurant)->create(['role' => UserRole::Owner]);

        return [$restaurant, $owner];
    }

    public function test_guests_are_redirected_from_the_quick_form(): void
    {
        $this->get(route('reservations.quick.create'))->assertRedirect('/login');
    }

    public function test_form_renders_with_smart_defaults(): void
    {
        [$restaurant, $owner] = $this->ownerWithRestaurant();
        Table::factory()->for($restaurant)->create(['seats' => 4]);

        $this->actingAs($owner)
            ->get(route('reservations.quick.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The Vue page lands in #344; skip the component-file existence check.
                ->component('Reservations/Quick', false)
                ->where('defaults.party_size', 2)
                ->has('defaults.date')
                ->has('defaults.time')
                ->has('availability.state')
                ->has('availability.alternative_slots')
                ->has('tables.data', 1)
            );
    }

    public function test_availability_reflects_query_parameters(): void
    {
        [$restaurant, $owner] = $this->ownerWithRestaurant();
        Table::factory()->for($restaurant)->create(['seats' => 6]);

        $this->actingAs($owner)
            ->get(route('reservations.quick.create', [
                'date' => '2026-06-15', // Monday
                'time' => '19:00',
                'party_size' => 4,
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // The Vue page lands in #344; skip the component-file existence check.
                ->component('Reservations/Quick', false)
                ->where('defaults.date', '2026-06-15')
                ->where('defaults.time', '19:00')
                ->where('defaults.party_size', 4)
                ->where('availability.state', 'free')
            );
    }

    public function test_party_size_above_the_cap_is_rejected(): void
    {
        [, $owner] = $this->ownerWithRestaurant();

        $this->actingAs($owner)
            ->get(route('reservations.quick.create', ['party_size' => 21]))
            ->assertSessionHasErrors('party_size');
    }

    public function test_only_active_tables_of_the_own_restaurant_are_returned(): void
    {
        [$restaurant, $owner] = $this->ownerWithRestaurant();
        $foreign = Restaurant::factory()->create();
        Table::factory()->for($restaurant)->create(['active' => true]);
        Table::factory()->for($restaurant)->inactive()->create();
        Table::factory()->for($foreign)->create(['active' => true]);

        $this->actingAs($owner)
            ->get(route('reservations.quick.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tables.data', 1));
    }

    public function test_user_without_a_restaurant_is_forbidden(): void
    {
        $user = User::factory()->create(); // restaurant_id is null

        $this->actingAs($user)
            ->get(route('reservations.quick.create'))
            ->assertForbidden();
    }
}
