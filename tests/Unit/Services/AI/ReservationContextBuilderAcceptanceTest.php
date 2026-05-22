<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\AI\ReservationContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One test per PRD-005 acceptance bullet for `ReservationContextBuilder`,
 * updated for PRD-011: availability is now the table-level free/tight/full
 * verdict from `SlotAvailability` plus the opening-hours framing. Depth tests
 * for the verdict and alternative-slot logic live in
 * `tests/Unit/Availability/SlotAvailabilityTest`; this file is the AC checklist.
 */
class ReservationContextBuilderAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private ReservationContextBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = $this->app->make(ReservationContextBuilder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFor(Restaurant $restaurant, Carbon $desiredAtUtc, int $partySize = 4): array
    {
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'desired_at' => $desiredAtUtc,
            'party_size' => $partySize,
        ]);

        return $this->builder->build($request);
    }

    /** AC 1: Happy path — open time window, a table can seat the party. */
    public function test_happy_path_open_window_with_a_free_table(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        Table::factory()->for($restaurant)->create(['seats' => 4]);

        // Wed 19:00 Berlin == 17:00 UTC, inside the dinner block.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC'));

        $this->assertTrue($context['availability']['is_open_at_desired_time']);
        $this->assertSame('free', $context['availability']['slot_state']);
        $this->assertTrue($context['availability']['is_available']);
        $this->assertNull($context['availability']['closed_reason']);
    }

    /** AC 2: Outside opening hours — closed_reason set, alternative_slots populated. */
    public function test_outside_opening_hours_populates_alternative_slots_and_closed_reason(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        Table::factory()->for($restaurant)->create(['seats' => 4]);

        // 14:00 UTC == 16:00 Berlin Wednesday → between lunch and dinner.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 14, 0, 0, 'UTC'));

        $this->assertFalse($context['availability']['is_open_at_desired_time']);
        $this->assertSame('ausserhalb_oeffnungszeiten', $context['availability']['closed_reason']);
        $this->assertSame('full', $context['availability']['slot_state']);
        // The dinner block opens later the same day → alternatives offered.
        $this->assertNotEmpty($context['availability']['alternative_slots']);
    }

    /** AC 3: Ruhetag — closed_reason 'ruhetag', alternative_slots empty. */
    public function test_ruhetag_yields_ruhetag_reason_and_no_alternatives(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        Table::factory()->for($restaurant)->create(['seats' => 4]);

        // Tuesday 2026-05-12 — Ruhetag in the factory's schedule.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 12, 17, 0, 0, 'UTC'));

        $this->assertFalse($context['availability']['is_open_at_desired_time']);
        $this->assertSame('ruhetag', $context['availability']['closed_reason']);
        $this->assertSame([], $context['availability']['alternative_slots']);
    }

    /** AC 4: Fully booked — slot_state full, alternatives offered. */
    public function test_fully_booked_yields_full_state_and_offers_alternatives(): void
    {
        // Zero buffer so the single booked table frees later the same evening.
        $restaurant = Restaurant::factory()->create([
            'timezone' => 'Europe/Berlin',
            'slot_buffer_minutes' => 0,
        ]);
        $table = Table::factory()->for($restaurant)->create(['seats' => 4]);

        $desiredAt = Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC');
        $booking = ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => $desiredAt,
            'party_size' => 4,
        ]);
        ReservationTableAssignment::factory()
            ->for($booking, 'reservationRequest')
            ->for($table)
            ->create();

        $context = $this->buildFor($restaurant, $desiredAt, partySize: 4);

        $this->assertSame('full', $context['availability']['slot_state']);
        $this->assertFalse($context['availability']['is_available']);
        $this->assertNotEmpty($context['availability']['alternative_slots']);
    }

    /** AC 5: Restaurant timezone ≠ server timezone — verdict + local time correct. */
    public function test_restaurant_timezone_differs_from_server_timezone(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        Table::factory()->for($restaurant)->create(['seats' => 4]);

        $desiredAt = Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC');
        $context = $this->buildFor($restaurant, $desiredAt);

        $this->assertSame('free', $context['availability']['slot_state']);
        // desired_at MUST be rendered in restaurant local time
        // (17:00 UTC + CEST offset = 19:00 Berlin).
        $this->assertSame('2026-05-13 19:00', $context['request']['desired_at']);
    }
}
