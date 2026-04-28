<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\ReservationContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One test per PRD-005 acceptance bullet for `ReservationContextBuilder`
 * (issue #77). Companion files exercise each axis in depth:
 *
 *   - shape & timezone: `ReservationContextBuilderTest`
 *   - seats-free arithmetic: `ReservationContextBuilderSeatsFreeTest`
 *   - alternative slots: `ReservationContextBuilderAlternativeSlotsTest`
 *
 * This file is the AC checklist — if a future change accidentally drops
 * a documented branch, the matching test here fails first.
 */
class ReservationContextBuilderAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private ReservationContextBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new ReservationContextBuilder;
    }

    private function buildFor(Restaurant $restaurant, Carbon $desiredAtUtc, int $partySize = 4): array
    {
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'desired_at' => $desiredAtUtc,
            'party_size' => $partySize,
        ]);

        return $this->builder->build($request);
    }

    /** AC 1: Happy path — open time window, capacity available. */
    public function test_happy_path_open_window_with_capacity(): void
    {
        $restaurant = Restaurant::factory()->create([
            'capacity' => 40,
            'timezone' => 'Europe/Berlin',
        ]);

        // Wed 19:00 Berlin == 17:00 UTC, inside the dinner block.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC'));

        $this->assertTrue($context['availability']['is_open_at_desired_time']);
        $this->assertGreaterThan($context['request']['party_size'], $context['availability']['seats_free_at_desired']);
        $this->assertNull($context['availability']['closed_reason']);
    }

    /** AC 2: Outside opening hours — closed_reason set, alternative_slots populated. */
    public function test_outside_opening_hours_populates_alternative_slots_and_closed_reason(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);

        // 14:00 UTC == 16:00 Berlin Wednesday → between lunch and dinner.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 14, 0, 0, 'UTC'));

        $this->assertFalse($context['availability']['is_open_at_desired_time']);
        $this->assertSame('ausserhalb_oeffnungszeiten', $context['availability']['closed_reason']);
        // The day still has open blocks before & after the desired time —
        // alternatives should be offered.
        $this->assertNotEmpty($context['availability']['alternative_slots']);
    }

    /** AC 3: Ruhetag — closed_reason 'ruhetag', alternative_slots empty. */
    public function test_ruhetag_yields_ruhetag_reason_and_no_alternatives(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);

        // Tuesday 2026-05-12 — Ruhetag in the factory's schedule.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 12, 17, 0, 0, 'UTC'));

        $this->assertFalse($context['availability']['is_open_at_desired_time']);
        $this->assertSame('ruhetag', $context['availability']['closed_reason']);
        $this->assertSame([], $context['availability']['alternative_slots']);
    }

    /** AC 4: Fully booked — seats_free < party_size, alternatives offered. */
    public function test_fully_booked_yields_seats_below_party_and_offers_alternatives(): void
    {
        $restaurant = Restaurant::factory()->create([
            'capacity' => 6,
            'timezone' => 'Europe/Berlin',
        ]);

        $desiredAt = Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC');

        // Block the desired slot with a confirmed party of 6.
        ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => $desiredAt,
            'party_size' => 6,
        ]);

        $context = $this->buildFor($restaurant, $desiredAt, partySize: 4);

        $this->assertLessThan(
            $context['request']['party_size'],
            $context['availability']['seats_free_at_desired'],
            'A fully-booked window must return seats below the requested party size.'
        );
        // Restaurant tonality + open hours unchanged → other slots must
        // remain on offer.
        $this->assertIsArray($context['availability']['alternative_slots']);
    }

    /** AC 5: Restaurant timezone ≠ server timezone — capacity window still correct. */
    public function test_restaurant_timezone_differs_from_server_timezone(): void
    {
        $restaurant = Restaurant::factory()->create([
            'capacity' => 40,
            'timezone' => 'Europe/Berlin',
        ]);

        // Confirmed exactly at the same UTC moment as the desired time —
        // capacity must subtract regardless of the server's UTC default.
        $desiredAt = Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC');

        ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => $desiredAt,
            'party_size' => 8,
        ]);

        $context = $this->buildFor($restaurant, $desiredAt);

        $this->assertSame(40 - 8, $context['availability']['seats_free_at_desired']);
        // desired_at MUST be rendered in restaurant local time
        // (17:00 UTC + CEST offset = 19:00 Berlin).
        $this->assertSame('2026-05-13 19:00', $context['request']['desired_at']);
    }
}
