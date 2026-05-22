<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Enums\ReservationStatus;
use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use App\Services\Availability\DTOs\TableCombination;
use App\Support\OpeningHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic table-availability for a restaurant (PRD-011).
 *
 * This is the single source of truth for "is this slot free/tight/full"; the AI
 * never decides availability, it only phrases the result. The result depends only
 * on the passed `$restaurantId`, never on the authenticated user — the queries
 * bypass the tenant RestaurantScope and scope by `$restaurantId` explicitly, so
 * availability is identical from a web request, a queued job, or the console.
 *
 * Occupancy is computed per table from active reservations, buffer-aware: a
 * reservation at `desired_at` occupies its table over the half-open footprint
 * `[desired_at - buffer, desired_at + slot_duration + buffer)`. Slot duration is
 * a fixed 90 min in V3 (per-restaurant configurability is a documented PRD-011
 * follow-up). A party that fits no single table can be seated on a two-table
 * combination via `combinable_with` (max two tables in V3); alternative slots
 * (#315) extend this service later.
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

        $tables = $this->activeTables($restaurant);
        if ($tables->isEmpty()) {
            return $this->fullResult($datetime);
        }

        $busyTableIds = $this->busyTableIds($restaurantId, $datetime, (int) $restaurant->slot_buffer_minutes);
        $freeTables = $tables->reject(fn (Table $table): bool => $busyTableIds->contains($table->id));

        $totalCapacity = (int) $tables->sum('seats');
        $busyCapacity = (int) $tables->whereIn('id', $busyTableIds->all())->sum('seats');
        $remainingRatio = $totalCapacity > 0 ? ($totalCapacity - $busyCapacity) / $totalCapacity : 0.0;
        $state = $remainingRatio <= self::TIGHT_THRESHOLD ? SlotState::Tight : SlotState::Free;

        $fitting = $this->combinationFrom($freeTables, $partySize);
        if ($fitting === null) {
            return $this->fullResult($datetime);
        }

        return new SlotAvailabilityResult(
            slotStart: $datetime,
            state: $state,
            suggestedTableId: $fitting->primaryTableId,
            combination: count($fitting->tableIds) > 1 ? $fitting : null,
            alternativeSlots: collect(),
        );
    }

    /**
     * Smallest table assignment that seats `$partySize` in the given slot, or
     * null if neither a single table nor a two-table combination fits. Prefers a
     * single table; falls back to the smallest compatible `combinable_with` pair.
     */
    public function suggestTableCombination(int $restaurantId, CarbonImmutable $datetime, int $partySize): ?TableCombination
    {
        $restaurant = Restaurant::query()->findOrFail($restaurantId);
        $tables = $this->activeTables($restaurant);

        if ($tables->isEmpty()) {
            return null;
        }

        $busyTableIds = $this->busyTableIds($restaurantId, $datetime, (int) $restaurant->slot_buffer_minutes);
        $freeTables = $tables->reject(fn (Table $table): bool => $busyTableIds->contains($table->id));

        return $this->combinationFrom($freeTables, $partySize);
    }

    /**
     * Active tables of the restaurant, independent of the tenant global scope.
     *
     * @return Collection<int, Table>
     */
    private function activeTables(Restaurant $restaurant): Collection
    {
        return $restaurant->tables()->withoutGlobalScopes()->where('active', true)->get();
    }

    /**
     * Pick a table assignment for `$partySize` from already-resolved free tables:
     * the smallest fitting single table, else the smallest compatible two-table
     * combination, else null. V3 caps combinations at two tables.
     *
     * @param  Collection<int, Table>  $freeTables
     */
    private function combinationFrom(Collection $freeTables, int $partySize): ?TableCombination
    {
        $single = $freeTables
            ->filter(fn (Table $table): bool => $table->seats >= $partySize)
            ->sortBy('seats')
            ->first();

        if ($single !== null) {
            return new TableCombination(
                primaryTableId: $single->id,
                tableIds: [$single->id],
                totalSeats: (int) $single->seats,
            );
        }

        $best = null;
        foreach ($freeTables as $primary) {
            foreach ($primary->combinable_with ?? [] as $partnerId) {
                $partner = $freeTables->firstWhere('id', $partnerId);
                if ($partner === null || $partner->id === $primary->id) {
                    continue;
                }

                $total = (int) $primary->seats + (int) $partner->seats;
                if ($total < $partySize) {
                    continue;
                }

                if ($best === null || $total < $best->totalSeats) {
                    $best = new TableCombination(
                        primaryTableId: $primary->id,
                        tableIds: [$primary->id, $partner->id],
                        totalSeats: $total,
                    );
                }
            }
        }

        return $best;
    }

    /**
     * Table ids occupied by an active reservation overlapping the buffered slot.
     *
     * With half-open footprints, a reservation overlaps the requested slot iff its
     * `desired_at` lies strictly inside `(datetime - (buffer + duration), datetime
     * + (duration + buffer))`; a reservation sitting exactly on a bound only
     * touches the slot edge and does not occupy it. Queries bypass the tenant
     * global scope so the result is independent of the authenticated user.
     *
     * @return Collection<int, int>
     */
    private function busyTableIds(int $restaurantId, CarbonImmutable $datetime, int $bufferMinutes): Collection
    {
        $windowStart = $datetime->subMinutes($bufferMinutes + self::SLOT_DURATION_MINUTES);
        $windowEnd = $datetime->addMinutes(self::SLOT_DURATION_MINUTES + $bufferMinutes);

        return ReservationRequest::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('status', array_map(fn (ReservationStatus $status): string => $status->value, self::OCCUPYING_STATUSES))
            ->where('desired_at', '>', $windowStart)
            ->where('desired_at', '<', $windowEnd)
            ->with(['tableAssignments' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'reservation_request_id', 'table_id')])
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
