<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OnboardingGatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_without_live_restaurant_is_redirected_to_the_wizard(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('onboarding.wizard'));
    }

    public function test_owner_on_a_wizard_route_is_not_redirected(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->get(route('onboarding.wizard'))->assertOk();
    }

    public function test_staff_of_a_pending_restaurant_sees_the_pending_placeholder(): void
    {
        $restaurant = Restaurant::factory()->pendingOnboarding()->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();

        $this->actingAs($staff)->get(route('dashboard'))->assertRedirect(route('onboarding.pending'));
    }

    public function test_users_of_a_live_restaurant_pass_through(): void
    {
        $restaurant = Restaurant::factory()->onboarded()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_a_user_without_a_restaurant_passes_through(): void
    {
        $orphan = User::factory()->create(['restaurant_id' => null]);

        // No restaurant → gate is a no-op (other guards handle empty dashboards).
        $this->actingAs($orphan)->get(route('dashboard'))->assertOk();
    }

    public function test_guests_are_unaffected(): void
    {
        $restaurant = Restaurant::factory()->create(['slug' => 'demo']);

        $this->get(route('public.reservations.create', $restaurant))->assertOk();
    }
}
