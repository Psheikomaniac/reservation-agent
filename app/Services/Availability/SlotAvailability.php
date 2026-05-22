<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Enums\ReservationStatus;
use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use App\Support\OpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic table-availability for a restaurant (PRD-011).
 *
 * This is the single source of truth for "is this slot free/tight/full"; the AI
 * never decides availability, it only phrases the result. Occupancy is computed
 * per table from active reservations, buffer-aware: a reservation at `desired_at`
 * blocks its table for `[desired_at - buffer, desired_at + slot_duration + buffer]`.
 * Slot duration is a fixed 90 min in V3 (per-restaurant configurability is a
 * documented PRD-011 follow-up). Single-table suggestion only in this task;
 * combinations (#314) and alternative slots (#315) extend this service later.
 */
final class SlotAvailability
{
    private const int SLOT_DURATION_MINUTES = 90;

    private const float TIGHT_THRESHOLD = 0.25;

    /**
     * Statuses that hold a table. `New` (not yet triaged), `Declined` and
     * `Cancelled` never occupy. Waitlist (PRD-013) is non-occupying by design.
     *
     * @var list<ReservationStatus>
     */
    private const array OCCUPYING_STATUSES = [
        ReservationStatus::InReview,
        ReservationStatus::Replied,
        ReservationStatus::Confirmed,
    ];

    public function forSlot(int $restaurantId, CarbonImmutable $datetime, int $partySize): SlotAvailabilityResult
    {
        $restaurant = Restaurant::query()->findOrFail($restaurantId);

        if (! OpeningHours::fromRestaurant($restaurant)->isOpenAt($datetime)) {
            return $this->fullResult($datetime);
        }

        $tables = $restaurant->tables()->where('active', true)->get();
        if ($tables->isEmpty()) {
            return $this->fullResult($datetime);
        }

        $busyTableIds = $this->busyTableIds($restaurantId, $datetime, (int) $restaurant->slot_buffer_minutes);

        $fittingTables = $tables
            ->reject(fn (Table $table): bool => $busyTableIds->contains($table->id))
            ->filter(fn (Table $table): bool => $table->seats >= $partySize);

        if ($fittingTables->isEmpty()) {
            return $this->fullResult($datetime);
        }

        $suggested = $fittingTables->sortBy('seats')->first();

        $totalCapacity = (int) $tables->sum('seats');
        $busyCapacity = (int) $tables->whereIn('id', $busyTableIds->all())->sum('seats');
        $remainingRatio = $totalCapacity > 0 ? ($totalCapacity - $busyCapacity) / $totalCapacity : 0.0;

        return new SlotAvailabilityResult(
            slotStart: $datetime,
            state: $remainingRatio <= self::TIGHT_THRESHOLD ? SlotState::Tight : SlotState::Free,
            suggestedTableId: $suggested->id,
            combination: null,
            alternativeSlots: collect(),
        );
    }

    /**
     * Table ids occupied by an active reservation overlapping the buffered slot.
     *
     * A reservation overlaps the requested slot when its `desired_at` falls in
     * `[datetime - (buffer + duration), datetime + (duration + buffer)]`.
     *
     * @return Collection<int, int>
     */
    private function busyTableIds(int $restaurantId, CarbonImmutable $datetime, int $bufferMinutes): Collection
    {
        $windowStart = $datetime->subMinutes($bufferMinutes + self::SLOT_DURATION_MINUTES);
        $windowEnd = $datetime->addMinutes(self::SLOT_DURATION_MINUTES + $bufferMinutes);

        return ReservationRequest::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('status', array_map(fn (ReservationStatus $status): string => $status->value, self::OCCUPYING_STATUSES))
            ->whereBetween('desired_at', [$windowStart, $windowEnd])
            ->with('tableAssignments:id,reservation_request_id,table_id')
            ->get()
            ->flatMap(fn (ReservationRequest $reservation): Collection => $reservation->tableAssignments->pluck('table_id'))
            ->unique()
            ->values();
    }

    private function fullResult(CarbonImmutable $datetime): SlotAvailabilityResult
    {
        return new SlotAvailabilityResult(
            slotStart: $datetime,
            state: SlotState::Full,
            suggestedTableId: null,
            combination: null,
            alternativeSlots: collect(),
        );
    }
}
