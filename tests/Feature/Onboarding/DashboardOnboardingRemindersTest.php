<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class DashboardOnboardingRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_pending_optional_steps(): void
    {
        // Live restaurant (passes the gate) but no staff invited yet.
        $restaurant = Restaurant::factory()->onboarded()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingReminders', ['team']));
    }

    public function test_no_reminders_once_a_staff_invitation_exists(): void
    {
        $restaurant = Restaurant::factory()->onboarded()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();
        Invitation::factory()->for($restaurant)->create(['role' => UserRole::Staff]);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('onboardingReminders', []));
    }
}
