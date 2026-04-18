<?php

namespace Tests\Feature\Models\Scopes;

use App\Models\FailedEmailImport;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Scopes\RestaurantScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_sees_only_reservation_requests_of_own_restaurant(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        $ownRequest = ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        $this->actingAs($userA);

        $ids = ReservationRequest::query()->pluck('id')->all();

        $this->assertContains($ownRequest->id, $ids);
        $this->assertNotContains($foreignRequest->id, $ids);
    }

    public function test_authenticated_user_cannot_find_foreign_reservation_request_by_primary_key(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        $foreignRequest = ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        $this->actingAs($userA);

        $this->assertNull(ReservationRequest::query()->find($foreignRequest->id));
    }

    public function test_authenticated_user_sees_only_failed_email_imports_of_own_restaurant(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        $ownImport = FailedEmailImport::factory()->forRestaurant($restaurantA)->create();
        $foreignImport = FailedEmailImport::factory()->forRestaurant($restaurantB)->create();

        $this->actingAs($userA);

        $ids = FailedEmailImport::query()->pluck('id')->all();

        $this->assertContains($ownImport->id, $ids);
        $this->assertNotContains($foreignImport->id, $ids);
    }

    public function test_authenticated_user_sees_only_reservation_replies_belonging_to_own_restaurant(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        $ownRequest = ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        $ownReply = ReservationReply::factory()->forReservationRequest($ownRequest)->create();
        $foreignReply = ReservationReply::factory()->forReservationRequest($foreignRequest)->create();

        $this->actingAs($userA);

        $ids = ReservationReply::query()->pluck('id')->all();

        $this->assertContains($ownReply->id, $ids);
        $this->assertNotContains($foreignReply->id, $ids);
    }

    public function test_unauthenticated_context_bypasses_the_scope_on_all_tenant_models(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();

        ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        $requestB = ReservationRequest::factory()->forRestaurant($restaurantB)->create();
        FailedEmailImport::factory()->forRestaurant($restaurantA)->create();
        FailedEmailImport::factory()->forRestaurant($restaurantB)->create();
        ReservationReply::factory()->forReservationRequest($requestB)->create();

        $this->assertGuest();

        $this->assertSame(2, ReservationRequest::query()->count());
        $this->assertSame(2, FailedEmailImport::query()->count());
        $this->assertSame(1, ReservationReply::query()->count());
    }

    public function test_without_global_scope_returns_all_records_for_internal_use(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        $this->actingAs($userA);

        $this->assertSame(1, ReservationRequest::query()->count());
        $this->assertSame(
            2,
            ReservationRequest::query()->withoutGlobalScope(RestaurantScope::class)->count()
        );
    }

    public function test_authenticated_user_without_restaurant_id_sees_no_records_fail_safe(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $orphanUser = User::factory()->create(['restaurant_id' => null]);

        ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        $this->actingAs($orphanUser);

        $this->assertSame(0, ReservationRequest::query()->count());
        $this->assertSame(0, FailedEmailImport::query()->count());
    }

    public function test_scope_filters_cross_tenant_even_when_counting_with_joins(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        ReservationRequest::factory()->forRestaurant($restaurantA)->count(3)->create();
        ReservationRequest::factory()->forRestaurant($restaurantB)->count(5)->create();

        $this->actingAs($userA);

        $this->assertSame(3, ReservationRequest::query()->count());
    }
}
