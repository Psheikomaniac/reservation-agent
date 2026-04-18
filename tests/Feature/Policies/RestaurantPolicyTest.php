<?php

namespace Tests\Feature\Policies;

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use App\Policies\RestaurantPolicy;
use App\Providers\AuthServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RestaurantPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_is_registered_in_auth_service_provider(): void
    {
        $this->assertSame(
            RestaurantPolicy::class,
            AuthServiceProvider::$policies[Restaurant::class] ?? null
        );

        $this->assertInstanceOf(
            RestaurantPolicy::class,
            Gate::getPolicyFor(Restaurant::class)
        );
    }

    public function test_member_of_restaurant_may_view_it(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $this->assertTrue($user->can('view', $restaurant));
    }

    public function test_foreign_user_may_not_view_restaurant(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($home)->create();

        $this->assertFalse($user->can('view', $foreign));
    }

    public function test_member_of_restaurant_may_update_it(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $this->assertTrue($user->can('update', $restaurant));
    }

    public function test_foreign_user_may_not_update_restaurant(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($home)->create();

        $this->assertFalse($user->can('update', $foreign));
    }

    public function test_owner_of_restaurant_may_manage_it(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()
            ->forRestaurant($restaurant)
            ->create(['role' => UserRole::Owner]);

        $this->assertTrue($owner->can('manage', $restaurant));
    }

    public function test_staff_member_may_not_manage_own_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $staff = User::factory()
            ->forRestaurant($restaurant)
            ->create(['role' => UserRole::Staff]);

        $this->assertFalse($staff->can('manage', $restaurant));
    }

    public function test_owner_of_other_restaurant_may_not_manage_foreign_restaurant(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $foreignOwner = User::factory()
            ->forRestaurant($home)
            ->create(['role' => UserRole::Owner]);

        $this->assertFalse($foreignOwner->can('manage', $foreign));
    }

    public function test_authorize_throws_authorization_exception_for_foreign_access(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($home)->create();

        $this->expectException(AuthorizationException::class);

        Gate::forUser($user)->authorize('view', $foreign);
    }
}
