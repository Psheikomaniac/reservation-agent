<?php

namespace Tests\Feature\Policies;

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Policies\ReservationRequestPolicy;
use App\Providers\AuthServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ReservationRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_is_registered_in_auth_service_provider(): void
    {
        $this->assertSame(
            ReservationRequestPolicy::class,
            AuthServiceProvider::$policies[ReservationRequest::class] ?? null
        );

        $this->assertInstanceOf(
            ReservationRequestPolicy::class,
            Gate::getPolicyFor(ReservationRequest::class)
        );
    }

    public function test_member_of_restaurant_may_view_own_request(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();

        $this->assertTrue($user->can('view', $request));
    }

    public function test_foreign_user_may_not_view_request(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($home)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($foreign)->create();

        $this->assertFalse($user->can('view', $foreignRequest));
    }

    public function test_member_of_restaurant_may_update_own_request(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();

        $this->assertTrue($user->can('update', $request));
    }

    public function test_foreign_user_may_not_update_request(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($home)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($foreign)->create();

        $this->assertFalse($user->can('update', $foreignRequest));
    }

    public function test_member_of_restaurant_may_delete_own_request(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();

        $this->assertTrue($user->can('delete', $request));
    }

    public function test_foreign_user_may_not_delete_request(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($home)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($foreign)->create();

        $this->assertFalse($user->can('delete', $foreignRequest));
    }

    public function test_user_with_restaurant_may_bulk_update(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $this->assertTrue($user->can('bulkUpdate', ReservationRequest::class));
    }

    public function test_orphan_user_without_restaurant_may_not_bulk_update(): void
    {
        $user = User::factory()->create(['restaurant_id' => null]);

        $this->assertFalse($user->can('bulkUpdate', ReservationRequest::class));
    }

    public function test_authorize_throws_authorization_exception_for_foreign_request_access(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($home)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($foreign)->create();

        $this->expectException(AuthorizationException::class);

        Gate::forUser($user)->authorize('view', $foreignRequest);
    }
}
