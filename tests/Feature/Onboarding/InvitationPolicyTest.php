<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class InvitationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_may_invite_staff_but_staff_and_restaurantless_users_may_not(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->owner()->forRestaurant($restaurant)->create();
        $staff = User::factory()->forRestaurant($restaurant)->create();
        $restaurantless = User::factory()->create(['restaurant_id' => null]);

        $this->assertTrue(Gate::forUser($owner)->allows('create', Invitation::class));
        $this->assertFalse(Gate::forUser($staff)->allows('create', Invitation::class));
        $this->assertFalse(Gate::forUser($restaurantless)->allows('create', Invitation::class));
    }
}
