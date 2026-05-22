<?php

declare(strict_types=1);

namespace App\Services\Availability\DTOs;

use App\Enums\SlotState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic availability verdict for one slot (PRD-011).
 *
 * Produced by SlotAvailability::forSlot(). `suggestedTableId` / `combination`
 * are the table proposal (null when full); `alternativeSlots` is populated with
 * up to three later free slots when the requested slot is full, so consumer UIs
 * (PRD-012) can offer next-best times.
 */
final readonly class SlotAvailabilityResult
{
    /**
     * @param  Collection<int, CarbonImmutable>  $alternativeSlots
     */
    public function __construct(
        public CarbonImmutable $slotStart,
        public SlotState $state,
        public ?int $suggestedTableId,
        public ?TableCombination $combination,
        public Collection $alternativeSlots,
    ) {}
}
