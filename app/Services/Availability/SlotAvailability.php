<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Enums\ReservationStatus;
use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\Availability\DTOs\DayAvailability;
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
 * combination via `combinable_with` (max two tables in V3). When a requested slot
 * is full, `forSlot` also offers up to three later same-day slots as alternatives.
 */
final class SlotAvailability
{
    private const int SLOT_DURATION_MINUTES = 90;

    private const int SLOT_INCREMENT_MINUTES = 30;

    private const int MAX_ALTERNATIVE_SLOTS = 3;

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
        $tables = $this->activeTables($restaurant);

        $result = $this->evaluateLoadedSlot($restaurant, $tables, $datetime, $partySize);

        if ($result->state !== SlotState::Full) {
            return $result;
        }

        // Only a full slot needs next-best times. The restaurant and its tables
        // are resolved once and reused for every candidate so the alternative
        // search does not re-query them per step.
        return new SlotAvailabilityResult(
            slotStart: $result->slotStart,
            state: $result->state,
            suggestedTableId: $result->suggestedTableId,
            combination: $result->combination,
            alternativeSlots: $this->findAlternativeSlots($restaurant, $tables, $datetime, $partySize),
        );
    }

    /**
     * Active tables free for the buffered slot, ordered for deterministic output.
     *
     * @return Collection<int, Table>
     */
    public function freeTablesAt(int $restaurantId, CarbonImmutable $datetime): Collection
    {
        $restaurant = Restaurant::query()->findOrFail($restaurantId);
        $busyTableIds = $this->busyTableIds($restaurantId, $datetime, (int) $restaurant->slot_buffer_minutes);

        return $this->activeTables($restaurant)
            ->reject(fn (Table $table): bool => $busyTableIds->contains($table->id))
            ->values();
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
     * Full-day occupancy grid: one slot per 30 minutes within opening hours,
     * each evaluated for a party of one (table-level occupancy, not party fit).
     *
     * Loads the restaurant, its active tables and the day's active reservations
     * once and evaluates every slot in memory, so the whole grid costs a bounded
     * number of queries rather than one per slot.
     */
    public function forDay(int $restaurantId, CarbonImmutable $date): DayAvailability
    {
        $restaurant = Restaurant::query()->findOrFail($restaurantId);
        $tables = $this->activeTables($restaurant);
        $buffer = (int) $restaurant->slot_buffer_minutes;

        $reservations = $this->dayReservations($restaurantId, $date, $buffer);

        $slots = collect();
        foreach (OpeningHours::fromRestaurant($restaurant)->blocksAt($date) as $window) {
            $cursor = $date->setTimeFromTimeString($window['from']);
            $close = $date->setTimeFromTimeString($window['to']);

            while ($cursor->lt($close)) {
                $busyTableIds = $this->busyTableIdsFrom($reservations, $cursor, $buffer);
                $slots->push($this->slotVerdict($tables, $busyTableIds, $cursor, partySize: 1));
                $cursor = $cursor->addMinutes(self::SLOT_INCREMENT_MINUTES);
            }
        }

        $reservedSeats = (int) $reservations
            ->filter(fn (ReservationRequest $reservation): bool => $reservation->desired_at->toDateString() === $date->toDateString())
            ->sum('party_size');

        return new DayAvailability(
            date: $date->startOfDay(),
            slots: $slots,
            totalCapacity: (int) $tables->sum('seats'),
            reservedSeats: $reservedSeats,
        );
    }

    /**
     * Evaluate one slot against pre-resolved restaurant + active tables, running
     * its own per-slot occupancy query.
     *
     * Kept free of recursion (never calls the alternative search) so callers can
     * loop over many candidates cheaply: only the per-slot occupancy query runs
     * per call, not the restaurant/table lookups.
     *
     * @param  Collection<int, Table>  $tables
     */
    private function evaluateLoadedSlot(Restaurant $restaurant, Collection $tables, CarbonImmutable $datetime, int $partySize): SlotAvailabilityResult
    {
        if (! OpeningHours::fromRestaurant($restaurant)->isOpenAt($datetime)) {
            return $this->fullResult($datetime);
        }

        $busyTableIds = $this->busyTableIds($restaurant->id, $datetime, (int) $restaurant->slot_buffer_minutes);

        return $this->slotVerdict($tables, $busyTableIds, $datetime, $partySize);
    }

    /**
     * Pure free/tight/full verdict for a slot given pre-resolved tables and the
     * table ids busy at that slot. No queries — safe to call in a tight loop.
     *
     * @param  Collection<int, Table>  $tables
     * @param  Collection<int, int>  $busyTableIds
     */
    private function slotVerdict(Collection $tables, Collection $busyTableIds, CarbonImmutable $datetime, int $partySize): SlotAvailabilityResult
    {
        if ($tables->isEmpty()) {
            return $this->fullResult($datetime);
        }

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
     * Active tables of the restaurant, independent of the tenant global scope.
     *
     * Ordered by `sort_order` then `id` so that downstream tie-breaks — most
     * notably which table becomes the primary of a symmetric combination — are
     * deterministic regardless of database row order or engine.
     *
     * @return Collection<int, Table>
     */
    private function activeTables(Restaurant $restaurant): Collection
    {
        return $restaurant->tables()
            ->withoutGlobalScopes()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
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

    /**
     * Active reservations (with table assignments) that could occupy any slot on
     * `$date`, loaded in one query. The window is padded by buffer + duration on
     * both sides so reservations spilling in from the adjacent days are included.
     *
     * @return Collection<int, ReservationRequest>
     */
    private function dayReservations(int $restaurantId, CarbonImmutable $date, int $bufferMinutes): Collection
    {
        $margin = $bufferMinutes + self::SLOT_DURATION_MINUTES;

        return ReservationRequest::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('status', array_map(fn (ReservationStatus $status): string => $status->value, self::OCCUPYING_STATUSES))
            ->where('desired_at', '>=', $date->startOfDay()->subMinutes($margin))
            ->where('desired_at', '<=', $date->endOfDay()->addMinutes($margin))
            ->with(['tableAssignments' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'reservation_request_id', 'table_id')])
            ->get();
    }

    /**
     * In-memory variant of {@see busyTableIds}: which table ids the already-loaded
     * reservations occupy at `$datetime`, using the same half-open window. Lets
     * the day grid evaluate every slot without a query per slot.
     *
     * @param  Collection<int, ReservationRequest>  $reservations
     * @return Collection<int, int>
     */
    private function busyTableIdsFrom(Collection $reservations, CarbonImmutable $datetime, int $bufferMinutes): Collection
    {
        $windowStart = $datetime->subMinutes($bufferMinutes + self::SLOT_DURATION_MINUTES);
        $windowEnd = $datetime->addMinutes(self::SLOT_DURATION_MINUTES + $bufferMinutes);

        return $reservations
            ->filter(fn (ReservationRequest $reservation): bool => $reservation->desired_at->gt($windowStart) && $reservation->desired_at->lt($windowEnd))
            ->flatMap(fn (ReservationRequest $reservation): Collection => $reservation->tableAssignments->pluck('table_id'))
            ->unique()
            ->values();
    }

    /**
     * Up to three later same-day slots (30-min steps) that are not full, used to
     * offer next-best times when the requested slot is full. Closed and full
     * candidates are skipped because evaluateLoadedSlot reports them as full.
     *
     * The day boundary is taken in the restaurant's local timezone so the search
     * stops at the restaurant's midnight, consistent with the opening-hours check,
     * regardless of the timezone `$after` carries.
     *
     * @param  Collection<int, Table>  $tables
     * @return Collection<int, CarbonImmutable>
     */
    private function findAlternativeSlots(Restaurant $restaurant, Collection $tables, CarbonImmutable $after, int $partySize): Collection
    {
        $alternatives = collect();
        $candidate = $after->addMinutes(self::SLOT_INCREMENT_MINUTES);
        $endOfDay = $after->setTimezone($restaurant->timezone)->endOfDay();

        while ($alternatives->count() < self::MAX_ALTERNATIVE_SLOTS && $candidate->lte($endOfDay)) {
            if ($this->evaluateLoadedSlot($restaurant, $tables, $candidate, $partySize)->state !== SlotState::Full) {
                $alternatives->push($candidate);
            }

            $candidate = $candidate->addMinutes(self::SLOT_INCREMENT_MINUTES);
        }

        return $alternatives->values();
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
