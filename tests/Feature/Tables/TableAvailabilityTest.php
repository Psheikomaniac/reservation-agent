<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TableAvailabilityTest extends TestCase
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

    public function test_guests_are_redirected_from_availability(): void
    {
        $this->get(route('tables.availability', ['date' => '2026-06-15']))->assertRedirect('/login');
    }

    public function test_owner_sees_the_day_availability_grid_in_local_time(): void
    {
        [$restaurant, $owner] = $this->ownerWithRestaurant();
        Table::factory()->for($restaurant)->create(['seats' => 4]);

        $this->actingAs($owner)
            ->get(route('tables.availability', ['date' => '2026-06-15'])) // Monday
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tables')
                ->where('activeTab', 'availability')
                ->where('availability.date', '2026-06-15')
                // First Monday lunch slot is 11:30 in the restaurant's timezone.
                ->where('availability.slots.0.time', '11:30')
                ->has('availability.slots')
                ->has('availability.total_capacity')
                ->has('availability.reserved_seats')
                ->has('tables.data')
            );
    }

    public function test_availability_defaults_to_today_when_no_date_is_given(): void
    {
        [$restaurant, $owner] = $this->ownerWithRestaurant();
        Table::factory()->for($restaurant)->create();

        $this->actingAs($owner)
            ->get(route('tables.availability'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tables')
                ->where('availability.date', now()->format('Y-m-d'))
            );
    }

    public function test_invalid_date_is_rejected(): void
    {
        [, $owner] = $this->ownerWithRestaurant();

        $this->actingAs($owner)
            ->get(route('tables.availability', ['date' => 'not-a-date']))
            ->assertSessionHasErrors('date');
    }

    public function test_past_date_is_rejected(): void
    {
        [, $owner] = $this->ownerWithRestaurant();

        $this->actingAs($owner)
            ->get(route('tables.availability', ['date' => '2020-01-01']))
            ->assertSessionHasErrors('date');
    }

    public function test_staff_may_view_availability(): void
    {
        [$restaurant] = $this->ownerWithRestaurant();
        $staff = User::factory()->forRestaurant($restaurant)->create(['role' => UserRole::Staff]);
        Table::factory()->for($restaurant)->create();

        $this->actingAs($staff)
            ->get(route('tables.availability', ['date' => '2026-06-15']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Tables')
                ->where('activeTab', 'availability')
            );
    }

    public function test_user_without_a_restaurant_is_forbidden(): void
    {
        $user = User::factory()->create(); // restaurant_id is null

        $this->actingAs($user)
            ->get(route('tables.availability', ['date' => '2026-06-15']))
            ->assertForbidden();
    }

    public function test_only_active_tables_of_the_own_restaurant_are_returned(): void
    {
        [$restaurant, $owner] = $this->ownerWithRestaurant();
        $foreign = Restaurant::factory()->create();
        Table::factory()->for($restaurant)->create(['active' => true]);
        Table::factory()->for($restaurant)->inactive()->create();
        Table::factory()->for($foreign)->create(['active' => true]);

        $this->actingAs($owner)
            ->get(route('tables.availability', ['date' => '2026-06-15']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('tables.data', 1));
    }
}
