<?php

namespace Tests\Feature\Models\Scopes;

use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EagerLoadIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_eager_loaded_replies_do_not_leak_across_tenants_via_has_many(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        $ownRequest = ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        ReservationReply::factory()->forReservationRequest($ownRequest)->count(2)->create();
        ReservationReply::factory()->forReservationRequest($foreignRequest)->count(3)->create();

        $this->actingAs($userA);

        $requests = ReservationRequest::query()->with('replies')->get();

        $this->assertCount(1, $requests);
        $this->assertTrue($requests->first()->is($ownRequest));
        $this->assertCount(2, $requests->first()->replies);
    }

    public function test_eager_loaded_latest_reply_does_not_leak_across_tenants_via_has_one(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        $ownRequest = ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        $ownReply = ReservationReply::factory()->forReservationRequest($ownRequest)->create();
        ReservationReply::factory()->forReservationRequest($foreignRequest)->create();

        $this->actingAs($userA);

        $requests = ReservationRequest::query()->with('latestReply')->get();

        $this->assertCount(1, $requests);
        $this->assertTrue($requests->first()->latestReply->is($ownReply));
    }

    public function test_with_count_on_replies_counts_only_own_tenant_rows(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        $ownRequest = ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        ReservationReply::factory()->forReservationRequest($ownRequest)->count(4)->create();
        ReservationReply::factory()->forReservationRequest($foreignRequest)->count(7)->create();

        $this->actingAs($userA);

        $requests = ReservationRequest::query()->withCount('replies')->get();

        $this->assertCount(1, $requests);
        $this->assertSame(4, $requests->first()->replies_count);
    }

    public function test_eager_loaded_reservation_request_is_null_for_foreign_replies_when_scope_bypassed(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        $ownRequest = ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        $ownReply = ReservationReply::factory()->forReservationRequest($ownRequest)->create();
        $foreignReply = ReservationReply::factory()->forReservationRequest($foreignRequest)->create();

        $this->actingAs($userA);

        $replies = ReservationReply::query()
            ->withoutGlobalScopes()
            ->with('reservationRequest')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $replies);

        $ownLoaded = $replies->firstWhere('id', $ownReply->id);
        $foreignLoaded = $replies->firstWhere('id', $foreignReply->id);

        $this->assertNotNull($ownLoaded->reservationRequest);
        $this->assertTrue($ownLoaded->reservationRequest->is($ownRequest));

        $this->assertNull($foreignLoaded->reservationRequest);
    }

    public function test_eager_loaded_replies_on_cross_tenant_parent_return_empty_when_scope_bypassed(): void
    {
        [$restaurantA, $restaurantB] = Restaurant::factory()->count(2)->create();
        $userA = User::factory()->forRestaurant($restaurantA)->create();

        $ownRequest = ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        $foreignRequest = ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        ReservationReply::factory()->forReservationRequest($ownRequest)->count(2)->create();
        ReservationReply::factory()->forReservationRequest($foreignRequest)->count(3)->create();

        $this->actingAs($userA);

        $requests = ReservationRequest::query()
            ->withoutGlobalScopes()
            ->with('replies')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $requests);

        $ownLoaded = $requests->firstWhere('id', $ownRequest->id);
        $foreignLoaded = $requests->firstWhere('id', $foreignRequest->id);

        $this->assertCount(2, $ownLoaded->replies);
        $this->assertCount(0, $foreignLoaded->replies);
    }
}
