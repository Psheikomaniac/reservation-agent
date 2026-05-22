<?php

declare(strict_types=1);

namespace Tests\Unit\Availability;

use App\Enums\SlotState;
use App\Services\Availability\DTOs\DayAvailability;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use App\Services\Availability\DTOs\TableCombination;
use Carbon\CarbonImmutable;
use Error;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DTOTest extends TestCase
{
    public function test_slot_state_enum_has_three_string_backed_cases(): void
    {
        $this->assertCount(3, SlotState::cases());
        $this->assertSame('free', SlotState::Free->value);
        $this->assertSame('tight', SlotState::Tight->value);
        $this->assertSame('full', SlotState::Full->value);
    }

    public function test_slot_availability_result_is_constructible(): void
    {
        $result = new SlotAvailabilityResult(
            slotStart: CarbonImmutable::parse('2026-06-15 19:00'),
            state: SlotState::Free,
            suggestedTableId: 7,
            combination: null,
            alternativeSlots: collect(),
        );

        $this->assertSame(SlotState::Free, $result->state);
        $this->assertSame(7, $result->suggestedTableId);
        $this->assertNull($result->combination);
        $this->assertInstanceOf(Collection::class, $result->alternativeSlots);
        $this->assertSame('2026-06-15 19:00', $result->slotStart->format('Y-m-d H:i'));
    }

    public function test_slot_availability_result_carries_a_table_combination(): void
    {
        $combination = new TableCombination(primaryTableId: 5, tableIds: [5, 6], totalSeats: 8);

        $result = new SlotAvailabilityResult(
            slotStart: CarbonImmutable::parse('2026-06-15 19:00'),
            state: SlotState::Free,
            suggestedTableId: 5,
            combination: $combination,
            alternativeSlots: collect(),
        );

        $this->assertSame($combination, $result->combination);
        $this->assertSame([5, 6], $result->combination->tableIds);
    }

    public function test_table_combination_exposes_primary_table_and_total_seats(): void
    {
        $combo = new TableCombination(primaryTableId: 5, tableIds: [5, 6], totalSeats: 8);

        $this->assertSame(5, $combo->primaryTableId);
        $this->assertSame([5, 6], $combo->tableIds);
        $this->assertSame(8, $combo->totalSeats);
    }

    public function test_day_availability_holds_slot_collection_and_totals(): void
    {
        $day = new DayAvailability(
            date: CarbonImmutable::parse('2026-06-15'),
            slots: collect(),
            totalCapacity: 50,
            reservedSeats: 12,
        );

        $this->assertSame(50, $day->totalCapacity);
        $this->assertSame(12, $day->reservedSeats);
        $this->assertInstanceOf(Collection::class, $day->slots);
        $this->assertSame('2026-06-15', $day->date->format('Y-m-d'));
    }

    public function test_dtos_are_readonly_and_reject_mutation(): void
    {
        $combo = new TableCombination(primaryTableId: 1, tableIds: [1], totalSeats: 4);

        $this->expectException(Error::class);
        // @phpstan-ignore-next-line — intentionally violating readonly to assert immutability
        $combo->totalSeats = 99;
    }
}
