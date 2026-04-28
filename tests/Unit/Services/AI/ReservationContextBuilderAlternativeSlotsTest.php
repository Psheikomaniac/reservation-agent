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
 * Coverage for the `alternative_slots` field of the AI context (PRD-005 /
 * issue #67). The builder must offer up to three free 30-min-spaced slots
 * inside the day's opening hours, sorted closest-first to the desired
 * time, excluding the desired time itself, and `[]` on a Ruhetag.
 */
class ReservationContextBuilderAlternativeSlotsTest extends TestCase
{
    use RefreshDatabase;

    private ReservationContextBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new ReservationContextBuilder;
    }

    private function makeRestaurant(int $capacity = 40): Restaurant
    {
        return Restaurant::factory()->create([
            'capacity' => $capacity,
            'timezone' => 'Europe/Berlin',
        ]);
    }

    /** Build for a Berlin-local desired time (passed as UTC for cast safety). */
    private function buildFor(Restaurant $restaurant, Carbon $desiredAtUtc, int $partySize = 4): array
    {
        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'desired_at' => $desiredAtUtc,
            'party_size' => $partySize,
        ]);

        return $this->builder->build($request);
    }

    public function test_returns_three_closest_free_slots_on_an_empty_evening(): void
    {
        $restaurant = $this->makeRestaurant();

        // 19:00 Berlin == 17:00 UTC on Wednesday — dinner block 18:00–22:30.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC'));

        $slots = $context['availability']['alternative_slots'];

        $this->assertCount(3, $slots);
        // Closest 30-min slots to 19:00 in [18:00, 22:30): 18:30, 19:30, 18:00.
        $this->assertSame(['2026-05-13 18:30', '2026-05-13 19:30', '2026-05-13 18:00'], $slots);
    }

    public function test_excludes_the_exact_desired_time(): void
    {
        $restaurant = $this->makeRestaurant();

        // 18:30 Berlin (16:30 UTC) is itself a 30-min slot — must not appear.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 16, 30, 0, 'UTC'));

        $slots = $context['availability']['alternative_slots'];

        $this->assertNotContains('2026-05-13 18:30', $slots);
    }

    public function test_returns_empty_on_ruhetag(): void
    {
        $restaurant = $this->makeRestaurant();

        // Tuesday 2026-05-12 is the factory's Ruhetag.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 12, 17, 30, 0, 'UTC'));

        $this->assertSame([], $context['availability']['alternative_slots']);
    }

    public function test_skips_slots_where_party_does_not_fit(): void
    {
        $restaurant = $this->makeRestaurant(capacity: 10);

        // Block 18:30 Berlin (16:30 UTC) by confirming an 8-seat party there.
        // For a party of 4, that slot has only 2 free → not eligible.
        ReservationRequest::factory()->confirmed()->forRestaurant($restaurant)->create([
            'desired_at' => Carbon::create(2026, 5, 13, 16, 30, 0, 'UTC'),
            'party_size' => 8,
        ]);

        // Desired 19:00 Berlin (17:00 UTC), party of 4.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC'), partySize: 4);

        $slots = $context['availability']['alternative_slots'];

        $this->assertNotContains('2026-05-13 18:30', $slots);
    }

    public function test_each_returned_slot_is_inside_an_opening_block(): void
    {
        $restaurant = $this->makeRestaurant();

        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC'));

        // Wed dinner block: 18:00–22:30 inclusive of from, exclusive of to.
        foreach ($context['availability']['alternative_slots'] as $slot) {
            $time = Carbon::createFromFormat('Y-m-d H:i', $slot, 'Europe/Berlin');
            $this->assertNotNull($time);

            $minutes = $time->hour * 60 + $time->minute;
            $insideLunch = $minutes >= 11 * 60 + 30 && $minutes < 14 * 60 + 30;
            $insideDinner = $minutes >= 18 * 60 && $minutes < 22 * 60 + 30;

            $this->assertTrue(
                $insideLunch || $insideDinner,
                "Slot {$slot} is outside Wednesday's opening blocks."
            );
        }
    }

    public function test_slots_are_ordered_closest_first(): void
    {
        $restaurant = $this->makeRestaurant();

        // Desired 20:00 Berlin (18:00 UTC) — closest 3 free 30-min slots in
        // [18:00, 22:30) excluding 20:00 itself: 19:30, 20:30, 19:00.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 18, 0, 0, 'UTC'));

        $this->assertSame(
            ['2026-05-13 19:30', '2026-05-13 20:30', '2026-05-13 19:00'],
            $context['availability']['alternative_slots']
        );
    }

    public function test_returns_at_most_three_slots_even_when_many_are_eligible(): void
    {
        $restaurant = $this->makeRestaurant();

        // Wednesday 19:00 — the Wed schedule has lunch 11:30–14:30 and
        // dinner 18:00–22:30, so plenty of free 30-min slots exist.
        $context = $this->buildFor($restaurant, Carbon::create(2026, 5, 13, 17, 0, 0, 'UTC'));

        $this->assertLessThanOrEqual(3, count($context['availability']['alternative_slots']));
    }
}
