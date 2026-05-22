<?php

declare(strict_types=1);

namespace App\Services\Availability\DTOs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Full-day availability grid for one restaurant (PRD-011).
 *
 * Produced by SlotAvailability::forDay(): one SlotAvailabilityResult per
 * 30-minute slot within opening hours. `totalCapacity` is the summed seats of
 * active tables; `reservedSeats` is the seats occupied by active reservations
 * that day, for the occupancy headline.
 */
final readonly class DayAvailability
{
    /**
     * @param  Collection<int, SlotAvailabilityResult>  $slots
     */
    public function __construct(
        public CarbonImmutable $date,
        public Collection $slots,
        public int $totalCapacity,
        public int $reservedSeats,
    ) {}
}
