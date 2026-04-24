<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RestaurantAvailableSeatsAtTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Monday 2026-04-20 in Europe/Berlin (CEST, +02:00).
     * Monday schedule per RestaurantFactory: lunch 11:30–14:30, dinner 18:00–22:30.
     */
    private function monday19Berlin(): CarbonInterface
    {
        return Carbon::create(2026, 4, 20, 19, 0, 0, 'Europe/Berlin');
    }

    private function makeRestaurant(int $capacity = 40): Restaurant
    {
        return Restaurant::factory()->create([
            'capacity' => $capacity,
            'timezone' => 'Europe/Berlin',
        ]);
    }

    private function confirmReservationAt(
        Restaurant $restaurant,
        CarbonInterface $at,
        int $partySize,
    ): ReservationRequest {
        return ReservationRequest::factory()
            ->confirmed()
            ->forRestaurant($restaurant)
            ->create([
                'desired_at' => $at,
                'party_size' => $partySize,
            ]);
    }

    public function test_empty_restaurant_reports_full_capacity(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        $seats = $restaurant->availableSeatsAt($this->monday19Berlin());

        $this->assertSame(40, $seats);
    }

    public function test_half_booked_restaurant_reports_remaining_seats(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        // Two confirmed parties inside the ±2h window around 19:00.
        $this->confirmReservationAt($restaurant, $this->monday19Berlin(), partySize: 6);
        $this->confirmReservationAt(
            $restaurant,
            $this->monday19Berlin()->copy()->addMinutes(30),
            partySize: 8,
        );

        $seats = $restaurant->availableSeatsAt($this->monday19Berlin());

        $this->assertSame(40 - 14, $seats);
    }

    public function test_fully_booked_restaurant_reports_zero(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 20);

        $this->confirmReservationAt($restaurant, $this->monday19Berlin(), partySize: 10);
        $this->confirmReservationAt(
            $restaurant,
            $this->monday19Berlin()->copy()->addMinutes(15),
            partySize: 10,
        );

        $this->assertSame(0, $restaurant->availableSeatsAt($this->monday19Berlin()));
    }

    public function test_overbooked_restaurant_clamps_to_zero_not_negative(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 10);

        $this->confirmReservationAt($restaurant, $this->monday19Berlin(), partySize: 8);
        $this->confirmReservationAt(
            $restaurant,
            $this->monday19Berlin()->copy()->addMinutes(15),
            partySize: 8,
        );

        $this->assertSame(0, $restaurant->availableSeatsAt($this->monday19Berlin()));
    }

    public function test_outside_opening_hours_returns_null(): void
    {
        $restaurant = $this->makeRestaurant();

        // Monday 16:00 Berlin — between lunch (11:30–14:30) and dinner (18:00–22:30).
        $closed = Carbon::create(2026, 4, 20, 16, 0, 0, 'Europe/Berlin');

        $this->assertNull($restaurant->availableSeatsAt($closed));
    }

    public function test_ruhetag_returns_null(): void
    {
        $restaurant = $this->makeRestaurant();

        // Tuesday 2026-04-21 — Ruhetag per factory schedule.
        $ruhetag = Carbon::create(2026, 4, 21, 19, 0, 0, 'Europe/Berlin');

        $this->assertNull($restaurant->availableSeatsAt($ruhetag));
    }

    public function test_non_confirmed_reservations_do_not_occupy_seats(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        $at = $this->monday19Berlin();

        foreach ([
            ReservationStatus::New,
            ReservationStatus::InReview,
            ReservationStatus::Replied,
            ReservationStatus::Declined,
            ReservationStatus::Cancelled,
        ] as $status) {
            ReservationRequest::factory()
                ->forRestaurant($restaurant)
                ->create([
                    'status' => $status,
                    'desired_at' => $at,
                    'party_size' => 8,
                ]);
        }

        $this->assertSame(40, $restaurant->availableSeatsAt($at));
    }

    public function test_reservations_of_other_restaurants_are_ignored(): void
    {
        $mine = $this->makeRestaurant(capacity: 40);
        $other = $this->makeRestaurant(capacity: 40);

        $this->confirmReservationAt($other, $this->monday19Berlin(), partySize: 20);

        $this->assertSame(40, $mine->availableSeatsAt($this->monday19Berlin()));
    }

    public function test_reservation_just_inside_window_counts(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        // Query at 19:00; window is 17:00–21:00. A booking at 17:01 is inside.
        $insideEarly = $this->monday19Berlin()->copy()->subHours(2)->addMinute();
        $this->confirmReservationAt($restaurant, $insideEarly, partySize: 6);

        $this->assertSame(34, $restaurant->availableSeatsAt($this->monday19Berlin()));
    }

    public function test_reservation_just_outside_window_is_ignored(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        // Lunch sitting at 13:00 Berlin → 6h before a 19:00 query, well outside ±2h.
        $lunch = Carbon::create(2026, 4, 20, 13, 0, 0, 'Europe/Berlin');
        $this->confirmReservationAt($restaurant, $lunch, partySize: 10);

        $this->assertSame(40, $restaurant->availableSeatsAt($this->monday19Berlin()));
    }

    public function test_utc_input_produces_same_result_as_local_input(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        $this->confirmReservationAt($restaurant, $this->monday19Berlin(), partySize: 12);

        $localQuery = $this->monday19Berlin();
        $utcQuery = $localQuery->copy()->setTimezone('UTC');

        $this->assertSame(28, $restaurant->availableSeatsAt($localQuery));
        $this->assertSame(28, $restaurant->availableSeatsAt($utcQuery));
    }

    public function test_utc_input_whose_weekday_differs_from_local_is_resolved_correctly(): void
    {
        $restaurant = $this->makeRestaurant();

        // Monday 2026-04-20 23:30 UTC = Tuesday 2026-04-21 01:30 Berlin (Ruhetag) → null.
        $utcTueBerlin = Carbon::create(2026, 4, 20, 23, 30, 0, 'UTC');

        $this->assertNull($restaurant->availableSeatsAt($utcTueBerlin));
    }

    public function test_reservation_at_exact_window_upper_bound_is_included(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        // Query 19:00, window upper bound = 21:00. `whereBetween` is inclusive.
        $upper = $this->monday19Berlin()->copy()->addHours(2);
        $this->confirmReservationAt($restaurant, $upper, partySize: 5);

        $this->assertSame(35, $restaurant->availableSeatsAt($this->monday19Berlin()));
    }
}
