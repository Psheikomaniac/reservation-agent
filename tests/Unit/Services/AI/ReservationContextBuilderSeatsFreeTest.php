<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\ReservationContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Focused coverage of the `seats_free_at_desired` field of the AI context
 * (PRD-005 / issue #66). The underlying ±2h window logic lives in
 * `Restaurant::availableSeatsAt()` and has its own model-level tests; this
 * file exercises the field as it appears in the builder output, so the AI
 * prompt always sees the right number across every documented branch.
 */
class ReservationContextBuilderSeatsFreeTest extends TestCase
{
    use RefreshDatabase;

    private ReservationContextBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new ReservationContextBuilder;
    }

    /**
     * 17:00 UTC on Monday 2026-04-20 == 19:00 Europe/Berlin (CEST), which
     * sits inside the dinner block 18:00–22:30.
     *
     * Tests express times in UTC because the ReservationRequest's
     * `datetime` cast normalises stored values to the application timezone
     * (UTC) — using UTC directly keeps the test free from cast surprises.
     */
    private function monday19Berlin(): Carbon
    {
        return Carbon::create(2026, 4, 20, 17, 0, 0, 'UTC');
    }

    private function makeRestaurant(int $capacity = 40): Restaurant
    {
        return Restaurant::factory()->create([
            'capacity' => $capacity,
            'timezone' => 'Europe/Berlin',
        ]);
    }

    private function buildAt(Restaurant $restaurant, Carbon $desiredAt, int $partySize = 2): array
    {
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'desired_at' => $desiredAt,
            'party_size' => $partySize,
        ]);

        return $this->builder->build($request);
    }

    public function test_seats_free_equals_capacity_when_restaurant_is_empty(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        $context = $this->buildAt($restaurant, $this->monday19Berlin());

        $this->assertSame(40, $context['availability']['seats_free_at_desired']);
    }

    public function test_seats_free_subtracts_only_confirmed_party_sizes(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        // Confirmed reservations inside the ±2h window block seats.
        ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => $this->monday19Berlin(),
            'party_size' => 6,
        ]);
        ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => $this->monday19Berlin()->copy()->addMinutes(45),
            'party_size' => 4,
        ]);

        // Non-confirmed statuses must NOT reduce capacity.
        foreach ([
            ReservationStatus::New,
            ReservationStatus::InReview,
            ReservationStatus::Replied,
            ReservationStatus::Declined,
            ReservationStatus::Cancelled,
        ] as $status) {
            ReservationRequest::factory()->forRestaurant($restaurant)->create([
                'status' => $status,
                'desired_at' => $this->monday19Berlin(),
                'party_size' => 8,
            ]);
        }

        $context = $this->buildAt($restaurant, $this->monday19Berlin());

        $this->assertSame(40 - 10, $context['availability']['seats_free_at_desired']);
    }

    public function test_seats_free_clamps_to_zero_when_overbooked(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 10);

        ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => $this->monday19Berlin(),
            'party_size' => 8,
        ]);
        ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => $this->monday19Berlin()->copy()->addMinutes(15),
            'party_size' => 8,
        ]);

        $context = $this->buildAt($restaurant, $this->monday19Berlin());

        $this->assertSame(0, $context['availability']['seats_free_at_desired']);
    }

    public function test_seats_free_is_zero_when_closed_outside_opening_hours(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        // 14:00 UTC on Monday == 16:00 Europe/Berlin, between lunch
        // (until 14:30) and dinner (from 18:00).
        $closed = Carbon::create(2026, 4, 20, 14, 0, 0, 'UTC');

        $context = $this->buildAt($restaurant, $closed);

        $this->assertSame(0, $context['availability']['seats_free_at_desired']);
        $this->assertFalse($context['availability']['is_open_at_desired_time']);
    }

    public function test_seats_free_is_zero_on_ruhetag(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        // 17:00 UTC on Tuesday 2026-04-21 == 19:00 Berlin (CEST). Tuesday
        // is the Ruhetag in the factory schedule.
        $ruhetag = Carbon::create(2026, 4, 21, 17, 0, 0, 'UTC');

        $context = $this->buildAt($restaurant, $ruhetag);

        $this->assertSame(0, $context['availability']['seats_free_at_desired']);
    }

    public function test_seats_free_ignores_reservations_of_other_restaurants(): void
    {
        $mine = $this->makeRestaurant(capacity: 40);
        $other = $this->makeRestaurant(capacity: 40);

        ReservationRequest::factory()->confirmed()->forRestaurant($other)->create([
            'desired_at' => $this->monday19Berlin(),
            'party_size' => 20,
        ]);

        $context = $this->buildAt($mine, $this->monday19Berlin());

        $this->assertSame(40, $context['availability']['seats_free_at_desired']);
    }

    public function test_seats_free_ignores_reservations_outside_the_two_hour_window(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        // 11:00 UTC == 13:00 Berlin lunch sitting — 6 h before the 19:00
        // query, far outside ±2h.
        $lunch = Carbon::create(2026, 4, 20, 11, 0, 0, 'UTC');
        ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => $lunch,
            'party_size' => 10,
        ]);

        $context = $this->buildAt($restaurant, $this->monday19Berlin());

        $this->assertSame(40, $context['availability']['seats_free_at_desired']);
    }

    public function test_seats_free_uses_restaurant_timezone_even_when_input_is_utc(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 40);

        // 19:00 Berlin == 17:00 UTC during CEST.
        ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => Carbon::create(2026, 4, 20, 17, 0, 0, 'UTC'),
            'party_size' => 12,
        ]);

        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            // Same moment, expressed as UTC instead of Berlin local.
            'desired_at' => Carbon::create(2026, 4, 20, 17, 0, 0, 'UTC'),
            'party_size' => 2,
        ]);

        $context = $this->builder->build($request);

        $this->assertSame(28, $context['availability']['seats_free_at_desired']);
        $this->assertTrue($context['availability']['is_open_at_desired_time']);
    }
}
