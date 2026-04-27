<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $response = $this->post(route('reservations.bulk-status'), [
            'ids' => [1],
            'status' => ReservationStatus::Declined->value,
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_user_without_restaurant_is_forbidden(): void
    {
        $user = User::factory()->create(['restaurant_id' => null]);

        $response = $this->actingAs($user)->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => [1],
                'status' => ReservationStatus::Declined->value,
            ]
        );

        $response->assertForbidden();
    }

    public function test_validation_requires_at_least_one_id(): void
    {
        $user = $this->memberWithRestaurant();

        $response = $this->actingAs($user)->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => [],
                'status' => ReservationStatus::Declined->value,
            ]
        );

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHasErrors('ids');
    }

    public function test_validation_rejects_unknown_status_value(): void
    {
        $user = $this->memberWithRestaurant();

        $response = $this->actingAs($user)->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => [1],
                'status' => 'not-a-status',
            ]
        );

        $response->assertSessionHasErrors('status');
    }

    public function test_validation_rejects_non_integer_ids(): void
    {
        $user = $this->memberWithRestaurant();

        $response = $this->actingAs($user)->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => ['abc'],
                'status' => ReservationStatus::Declined->value,
            ]
        );

        $response->assertSessionHasErrors('ids.0');
    }

    public function test_happy_path_bulk_transitions_allowed_ids(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $first = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['status' => ReservationStatus::New]);
        $second = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['status' => ReservationStatus::New]);

        $response = $this->actingAs($user)->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => [$first->id, $second->id],
                'status' => ReservationStatus::Declined->value,
            ]
        );

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('bulkStatus', [
            'updated' => 2,
            'skipped' => [],
        ]);

        $this->assertSame(
            ReservationStatus::Declined,
            $first->fresh()->status,
        );
        $this->assertSame(
            ReservationStatus::Declined,
            $second->fresh()->status,
        );
    }

    public function test_skips_ids_whose_status_cannot_transition_to_target(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        // Confirmed -> Declined is not allowed by the state machine.
        $confirmed = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['status' => ReservationStatus::Confirmed]);
        $newOne = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['status' => ReservationStatus::New]);

        $response = $this->actingAs($user)->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => [$confirmed->id, $newOne->id],
                'status' => ReservationStatus::Declined->value,
            ]
        );

        $response->assertSessionHas('bulkStatus', [
            'updated' => 1,
            'skipped' => [$confirmed->id],
        ]);

        $this->assertSame(
            ReservationStatus::Confirmed,
            $confirmed->fresh()->status,
            'Confirmed reservation must not have been touched.'
        );
        $this->assertSame(
            ReservationStatus::Declined,
            $newOne->fresh()->status,
        );
    }

    public function test_skips_ids_belonging_to_another_restaurant(): void
    {
        [$home, $foreign] = Restaurant::factory()->count(2)->create();
        $user = User::factory()->forRestaurant($home)->create();

        $own = ReservationRequest::factory()
            ->forRestaurant($home)
            ->create(['status' => ReservationStatus::New]);
        $foreignReservation = ReservationRequest::factory()
            ->forRestaurant($foreign)
            ->create(['status' => ReservationStatus::New]);

        $response = $this->actingAs($user)->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => [$own->id, $foreignReservation->id],
                'status' => ReservationStatus::Declined->value,
            ]
        );

        $response->assertSessionHas('bulkStatus', [
            'updated' => 1,
            'skipped' => [$foreignReservation->id],
        ]);

        $this->assertSame(
            ReservationStatus::New,
            $foreignReservation->fresh()->status,
            'Foreign reservation must not have been mutated.'
        );
        $this->assertSame(
            ReservationStatus::Declined,
            $own->fresh()->status,
        );
    }

    public function test_skips_ids_that_do_not_exist(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $existing = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['status' => ReservationStatus::New]);
        $missingId = $existing->id + 999;

        $response = $this->actingAs($user)->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => [$existing->id, $missingId],
                'status' => ReservationStatus::Declined->value,
            ]
        );

        $response->assertSessionHas('bulkStatus', [
            'updated' => 1,
            'skipped' => [$missingId],
        ]);
    }

    public function test_uses_bounded_query_count_no_n_plus_one(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $reservations = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->count(20)
            ->create(['status' => ReservationStatus::New]);

        $this->actingAs($user);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => $reservations->pluck('id')->all(),
                'status' => ReservationStatus::Declined->value,
            ]
        );

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'reservation_requests'))
            ->all();

        DB::disableQueryLog();

        $response->assertSessionHas('bulkStatus', [
            'updated' => 20,
            'skipped' => [],
        ]);

        $selects = array_filter(
            $queries,
            fn (string $sql): bool => str_starts_with(strtolower(trim($sql)), 'select')
        );
        $updates = array_filter(
            $queries,
            fn (string $sql): bool => str_starts_with(strtolower(trim($sql)), 'update')
        );

        $this->assertCount(
            1,
            $selects,
            'Expected exactly one SELECT against reservation_requests, got: '
                .json_encode(array_values($selects))
        );
        $this->assertCount(
            1,
            $updates,
            'Expected exactly one UPDATE against reservation_requests, got: '
                .json_encode(array_values($updates))
        );
    }

    public function test_skipped_list_is_sorted_and_deduplicated(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        $confirmed = ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['status' => ReservationStatus::Confirmed]);

        $missing = $confirmed->id + 5_000;

        $response = $this->actingAs($user)->from(route('dashboard'))->post(
            route('reservations.bulk-status'),
            [
                'ids' => [$missing, $confirmed->id],
                'status' => ReservationStatus::Declined->value,
            ]
        );

        $skipped = session('bulkStatus.skipped');
        $this->assertSame([$confirmed->id, $missing], $skipped);
        $this->assertSame($skipped, array_values(array_unique($skipped)));
        $this->assertSame($skipped, [...$skipped]);
        $this->assertSame($skipped, collect($skipped)->sort()->values()->all());
        $response->assertRedirect(route('dashboard'));
    }

    private function memberWithRestaurant(): User
    {
        $restaurant = Restaurant::factory()->create();

        return User::factory()->forRestaurant($restaurant)->create();
    }
}
