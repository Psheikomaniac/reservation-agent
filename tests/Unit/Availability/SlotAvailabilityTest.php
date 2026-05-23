<?php

declare(strict_types=1);

namespace Tests\Unit\Availability;

use App\Enums\ReservationStatus;
use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use App\Services\Availability\SlotAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SlotAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private SlotAvailability $service;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(SlotAvailability::class);

        // UTC keeps the slot time, stored desired_at, and opening-hours frame
        // identical, so the occupancy maths under test is not confounded by
        // timezone conversion (which OpeningHours already covers separately).
        $dinner = [['from' => '17:00', 'to' => '23:00']];
        $this->restaurant = Restaurant::factory()->create([
            'timezone' => 'UTC',
            'slot_buffer_minutes' => 90,
            'opening_hours' => [
                'mon' => $dinner, 'tue' => $dinner, 'wed' => $dinner, 'thu' => $dinner,
                'fri' => $dinner, 'sat' => $dinner, 'sun' => $dinner,
            ],
        ]);
    }

    private function slot(string $time, int $partySize): SlotAvailabilityResult
    {
        return $this->service->forSlot(
            $this->restaurant->id,
            CarbonImmutable::parse($time, 'UTC'),
            $partySize,
        );
    }

    private function occupy(Table $table, string $desiredAt, ReservationStatus $status, int $partySize = 4): void
    {
        $reservation = ReservationRequest::factory()
            ->for($this->restaurant)
            ->create([
                'desired_at' => CarbonImmutable::parse($desiredAt, 'UTC'),
                'party_size' => $partySize,
                'status' => $status,
            ]);

        ReservationTableAssignment::factory()
            ->for($reservation, 'reservationRequest')
            ->for($table)
            ->create();
    }

    public function test_marks_slot_free_when_a_fitting_table_is_unoccupied(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $result = $this->slot('2026-06-15 19:00', partySize: 4);

        $this->assertSame(SlotState::Free, $result->state);
        $this->assertNotNull($result->suggestedTableId);
    }

    public function test_suggests_the_smallest_fitting_table(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 8]);
        $small = Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $result = $this->slot('2026-06-15 19:00', partySize: 3);

        $this->assertSame(SlotState::Free, $result->state);
        $this->assertSame($small->id, $result->suggestedTableId);
    }

    public function test_marks_slot_full_when_no_table_fits_the_party_size(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 2]);

        $result = $this->slot('2026-06-15 19:00', partySize: 6);

        $this->assertSame(SlotState::Full, $result->state);
        $this->assertNull($result->suggestedTableId);
    }

    public function test_marks_slot_full_when_the_only_fitting_table_is_busy_in_the_buffer_window(): void
    {
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        // 18:00 reservation runs to 19:30 (+90 min) and the 90-min buffer extends
        // its footprint past 19:00, so the 19:00 slot must be Full.
        $this->occupy($table, '2026-06-15 18:00', ReservationStatus::Confirmed);

        $result = $this->slot('2026-06-15 19:00', partySize: 4);

        $this->assertSame(SlotState::Full, $result->state);
    }

    public function test_does_not_count_new_or_declined_reservations_as_occupying(): void
    {
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->occupy($table, '2026-06-15 19:00', ReservationStatus::New);
        $this->occupy(Table::factory()->for($this->restaurant)->create(['seats' => 4]), '2026-06-15 19:00', ReservationStatus::Declined);

        $result = $this->slot('2026-06-15 19:00', partySize: 4);

        $this->assertSame(SlotState::Free, $result->state);
    }

    public function test_does_not_count_waitlisted_reservations_as_occupying(): void
    {
        // PRD-013: waitlisted requests are parked, not seated — they must never
        // occupy a table, otherwise the slot would look full to the very waitlist
        // entry that is waiting for it to free up.
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->occupy($table, '2026-06-15 19:00', ReservationStatus::Waitlisted);

        $result = $this->slot('2026-06-15 19:00', partySize: 4);

        $this->assertSame(SlotState::Free, $result->state);
    }

    public function test_ignores_inactive_tables(): void
    {
        Table::factory()->for($this->restaurant)->inactive()->create(['seats' => 8]);

        $result = $this->slot('2026-06-15 19:00', partySize: 6);

        $this->assertSame(SlotState::Full, $result->state);
        $this->assertNull($result->suggestedTableId);
    }

    public function test_returns_full_for_a_slot_outside_opening_hours(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $result = $this->slot('2026-06-15 09:00', partySize: 2);

        $this->assertSame(SlotState::Full, $result->state);
    }

    public function test_returns_full_when_the_restaurant_has_no_active_tables(): void
    {
        $result = $this->slot('2026-06-15 19:00', partySize: 2);

        $this->assertSame(SlotState::Full, $result->state);
    }

    public function test_marks_slot_tight_when_remaining_capacity_is_at_or_below_the_threshold(): void
    {
        // 4 tables x 4 seats = 16 total. Occupy three (12 seats) at 19:00.
        // Remaining 4/16 = 25% -> Tight. The 4th table still fits party size 2.
        $tables = Table::factory()->for($this->restaurant)->count(4)->create(['seats' => 4]);
        foreach ([0, 1, 2] as $i) {
            $this->occupy($tables[$i], '2026-06-15 19:00', ReservationStatus::Confirmed);
        }

        $result = $this->slot('2026-06-15 19:00', partySize: 2);

        $this->assertSame(SlotState::Tight, $result->state);
        $this->assertSame($tables[3]->id, $result->suggestedTableId);
    }

    public function test_a_reservation_exactly_on_the_window_edge_does_not_occupy_the_slot(): void
    {
        // Window for a 19:00 slot with 90-min buffer + 90-min duration ends at
        // 22:00. A 22:00 reservation's footprint starts at 20:30 and only touches
        // the slot's 20:30 end (half-open intervals), so it must not block 19:00.
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->occupy($table, '2026-06-15 22:00', ReservationStatus::Confirmed);

        $result = $this->slot('2026-06-15 19:00', partySize: 4);

        $this->assertSame(SlotState::Free, $result->state);
        $this->assertSame($table->id, $result->suggestedTableId);
    }

    public function test_result_is_independent_of_the_authenticated_users_restaurant(): void
    {
        // A deterministic availability service must ignore the tenant global scope:
        // a logged-in user from a *different* restaurant must not make this
        // restaurant's free table disappear (which the RestaurantScope would do).
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $otherRestaurant = Restaurant::factory()->create();
        $this->actingAs(User::factory()->forRestaurant($otherRestaurant)->create());

        $result = $this->slot('2026-06-15 19:00', partySize: 4);

        $this->assertSame(SlotState::Free, $result->state);
        $this->assertNotNull($result->suggestedTableId);
    }

    public function test_no_combination_when_a_single_table_already_fits(): void
    {
        // A single fitting table is suggested as suggestedTableId; the combination
        // slot stays null. Alternative slots arrive in #315.
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $result = $this->slot('2026-06-15 19:00', partySize: 4);

        $this->assertNull($result->combination);
        $this->assertTrue($result->alternativeSlots->isEmpty());
    }

    public function test_suggest_combination_prefers_the_smallest_fitting_single_table(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 8, 'sort_order' => 1]);
        $small = Table::factory()->for($this->restaurant)->create(['seats' => 4, 'sort_order' => 2]);

        $combo = $this->service->suggestTableCombination(
            $this->restaurant->id,
            CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            partySize: 3,
        );

        $this->assertNotNull($combo);
        $this->assertSame([$small->id], $combo->tableIds);
        $this->assertSame($small->id, $combo->primaryTableId);
        $this->assertSame(4, $combo->totalSeats);
    }

    public function test_suggest_combination_returns_a_two_table_combo_when_no_single_table_fits(): void
    {
        [$a, $b] = $this->combinablePair(4, 4);

        $combo = $this->service->suggestTableCombination(
            $this->restaurant->id,
            CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            partySize: 6,
        );

        $this->assertNotNull($combo);
        $this->assertCount(2, $combo->tableIds);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $combo->tableIds);
        $this->assertSame(8, $combo->totalSeats);
        // Lower sort_order wins the primary slot deterministically (a before b).
        $this->assertSame($a->id, $combo->primaryTableId);
    }

    public function test_suggest_combination_returns_null_when_no_single_or_compatible_combo_fits(): void
    {
        // Two small tables that are NOT combinable with each other.
        Table::factory()->for($this->restaurant)->create(['seats' => 2]);
        Table::factory()->for($this->restaurant)->create(['seats' => 2]);

        $combo = $this->service->suggestTableCombination(
            $this->restaurant->id,
            CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            partySize: 8,
        );

        $this->assertNull($combo);
    }

    public function test_suggest_combination_does_not_combine_a_busy_table(): void
    {
        [$a, $b] = $this->combinablePair(4, 4);
        $this->occupy($a, '2026-06-15 19:00', ReservationStatus::Confirmed);

        $combo = $this->service->suggestTableCombination(
            $this->restaurant->id,
            CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            partySize: 6,
        );

        $this->assertNull($combo);
    }

    public function test_for_slot_returns_a_combination_when_no_single_table_fits(): void
    {
        [$a] = $this->combinablePair(4, 4);

        $result = $this->slot('2026-06-15 19:00', partySize: 6);

        $this->assertSame(SlotState::Free, $result->state);
        $this->assertNotNull($result->combination);
        $this->assertCount(2, $result->combination->tableIds);
        $this->assertSame($a->id, $result->combination->primaryTableId);
        $this->assertSame($result->combination->primaryTableId, $result->suggestedTableId);
    }

    public function test_free_tables_at_returns_unbooked_active_tables_only(): void
    {
        $busyTable = Table::factory()->for($this->restaurant)->create(['seats' => 4, 'sort_order' => 1]);
        $freeTable = Table::factory()->for($this->restaurant)->create(['seats' => 4, 'sort_order' => 2]);
        Table::factory()->for($this->restaurant)->inactive()->create(['seats' => 4, 'sort_order' => 3]);
        $this->occupy($busyTable, '2026-06-15 19:00', ReservationStatus::Confirmed);

        $free = $this->service->freeTablesAt(
            $this->restaurant->id,
            CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
        );

        $this->assertSame([$freeTable->id], $free->pluck('id')->all());
    }

    public function test_for_slot_fills_alternative_slots_with_next_free_slots_when_full(): void
    {
        // One table booked at 19:00. With 90-min buffer + 90-min duration the
        // table is busy until 22:00; the next 30-min slots inside the 17:00-23:00
        // window are 22:00 and 22:30, then 23:00 is closed.
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->occupy($table, '2026-06-15 19:00', ReservationStatus::Confirmed);

        $result = $this->slot('2026-06-15 19:00', partySize: 4);

        $this->assertSame(SlotState::Full, $result->state);
        $this->assertSame(
            ['22:00', '22:30'],
            $result->alternativeSlots->map(fn ($slot) => $slot->format('H:i'))->all(),
        );
    }

    public function test_alternative_slots_are_capped_at_three(): void
    {
        // Free all evening: an early-afternoon full slot (outside hours) yields the
        // first three open 30-min slots from 17:00.
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $result = $this->slot('2026-06-15 09:00', partySize: 4);

        $this->assertSame(SlotState::Full, $result->state);
        $this->assertCount(3, $result->alternativeSlots);
        $this->assertSame(
            ['17:00', '17:30', '18:00'],
            $result->alternativeSlots->map(fn ($slot) => $slot->format('H:i'))->all(),
        );
    }

    public function test_alternative_slots_stay_empty_for_a_free_slot(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $result = $this->slot('2026-06-15 19:00', partySize: 4);

        $this->assertSame(SlotState::Free, $result->state);
        $this->assertTrue($result->alternativeSlots->isEmpty());
    }

    public function test_alternative_slots_do_not_cross_the_local_day_boundary(): void
    {
        // Late slot in the restaurant's own timezone: the search must stop at the
        // restaurant's local midnight rather than walking into the next day.
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->occupy($table, '2026-06-15 22:30', ReservationStatus::Confirmed);

        // 22:30 is full (its own booking); 23:00 is closing time. There is no
        // later open slot today, so no alternatives — none roll over to tomorrow.
        $result = $this->slot('2026-06-15 22:30', partySize: 4);

        $this->assertSame(SlotState::Full, $result->state);
        $this->assertTrue($result->alternativeSlots->isEmpty());
    }

    public function test_for_day_returns_one_slot_per_30_minutes_within_opening_hours(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $day = $this->service->forDay($this->restaurant->id, CarbonImmutable::parse('2026-06-15', 'UTC'));

        // 17:00-23:00 in 30-min steps = 12 slots.
        $this->assertCount(12, $day->slots);
        $this->assertSame('17:00', $day->slots->first()->slotStart->format('H:i'));
        $this->assertSame('22:30', $day->slots->last()->slotStart->format('H:i'));
        $this->assertSame('2026-06-15', $day->date->toDateString());
    }

    public function test_for_day_computes_total_capacity_and_reserved_seats(): void
    {
        Table::factory()->for($this->restaurant)->count(2)->create(['seats' => 4]);
        $reservation = ReservationRequest::factory()->for($this->restaurant)->create([
            'desired_at' => CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            'party_size' => 3,
            'status' => ReservationStatus::Confirmed,
        ]);
        ReservationTableAssignment::factory()
            ->for($reservation, 'reservationRequest')
            ->for(Table::query()->first())
            ->create();

        $day = $this->service->forDay($this->restaurant->id, CarbonImmutable::parse('2026-06-15', 'UTC'));

        $this->assertSame(8, $day->totalCapacity);
        $this->assertSame(3, $day->reservedSeats);
    }

    public function test_for_day_marks_individual_slots_busy_from_loaded_reservations(): void
    {
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->occupy($table, '2026-06-15 19:00', ReservationStatus::Confirmed);

        $day = $this->service->forDay($this->restaurant->id, CarbonImmutable::parse('2026-06-15', 'UTC'));
        $byTime = $day->slots->keyBy(fn ($slot) => $slot->slotStart->format('H:i'));

        $this->assertSame(SlotState::Full, $byTime->get('19:00')->state);
        // 22:00 is past the buffered footprint of the 19:00 booking.
        $this->assertSame(SlotState::Free, $byTime->get('22:00')->state);
    }

    public function test_for_day_generates_slots_in_the_restaurant_timezone(): void
    {
        // A non-UTC restaurant: slots must line up with the restaurant's local
        // opening hours (via isOpenAt), not the input date's timezone.
        $dinner = [['from' => '17:00', 'to' => '23:00']];
        $restaurant = Restaurant::factory()->create([
            'timezone' => 'Europe/Berlin',
            'slot_buffer_minutes' => 90,
            'opening_hours' => [
                'mon' => $dinner, 'tue' => $dinner, 'wed' => $dinner, 'thu' => $dinner,
                'fri' => $dinner, 'sat' => $dinner, 'sun' => $dinner,
            ],
        ]);
        Table::factory()->for($restaurant)->create(['seats' => 4]);

        $day = $this->service->forDay($restaurant->id, CarbonImmutable::parse('2026-06-15', 'UTC'));

        $localTimes = $day->slots->map(fn ($slot) => $slot->slotStart->setTimezone('Europe/Berlin')->format('H:i'));
        $this->assertCount(12, $day->slots);
        $this->assertSame('17:00', $localTimes->first());
        $this->assertSame('22:30', $localTimes->last());
    }

    public function test_for_day_runs_a_bounded_number_of_queries(): void
    {
        Table::factory()->for($this->restaurant)->count(5)->create(['seats' => 4]);
        ReservationRequest::factory()->for($this->restaurant)->count(20)->create([
            'desired_at' => CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            'party_size' => 2,
            'status' => ReservationStatus::Confirmed,
        ]);

        DB::enableQueryLog();
        $this->service->forDay($this->restaurant->id, CarbonImmutable::parse('2026-06-15', 'UTC'));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // restaurant + tables + reservations + eager assignments, no per-slot N+1.
        $this->assertLessThanOrEqual(4, count($queries));
    }

    /**
     * Create two tables of the restaurant that can be combined with each other.
     *
     * @return array{0: Table, 1: Table}
     */
    private function combinablePair(int $seatsA, int $seatsB): array
    {
        $a = Table::factory()->for($this->restaurant)->create(['seats' => $seatsA, 'sort_order' => 1]);
        $b = Table::factory()->for($this->restaurant)->create(['seats' => $seatsB, 'sort_order' => 2]);
        $a->update(['combinable_with' => [$b->id]]);
        $b->update(['combinable_with' => [$a->id]]);

        return [$a, $b];
    }
}
