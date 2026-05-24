<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class OnboardingWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurant_exposes_users_and_invitations_relations(): void
    {
        $restaurant = Restaurant::factory()->create();
        User::factory()->forRestaurant($restaurant)->create();
        Invitation::factory()->for($restaurant)->create();

        $this->assertCount(1, $restaurant->users()->get());
        $this->assertCount(1, $restaurant->invitations()->get());
    }

    public function test_is_live_reflects_onboarding_completed_at_with_datetime_cast(): void
    {
        $restaurant = Restaurant::factory()->create(['onboarding_completed_at' => null]);
        $this->assertFalse($restaurant->isLive());

        $restaurant->update(['onboarding_completed_at' => now()]);
        $restaurant->refresh();

        $this->assertInstanceOf(Carbon::class, $restaurant->onboarding_completed_at);
        $this->assertTrue($restaurant->isLive());
    }

    public function test_factory_states(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();
        $this->assertSame(UserRole::Owner, $owner->role);

        $this->assertNotNull(Restaurant::factory()->onboarded()->create()->onboarding_completed_at);

        $pending = Restaurant::factory()->pendingOnboarding()->create();
        $this->assertNull($pending->onboarding_completed_at);
        $this->assertSame([], $pending->opening_hours);
    }
}
