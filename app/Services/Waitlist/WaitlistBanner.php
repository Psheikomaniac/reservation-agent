<?php

declare(strict_types=1);

namespace App\Services\Waitlist;

use App\Enums\ReservationStatus;
use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Services\Availability\SlotAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Surfaces waitlisted reservations whose desired slot is currently available
 * (PRD-013), so the dashboard can prompt the owner to pull a waiting guest in
 * when a cancellation frees a table.
 *
 * Read-only and stateless: the availability verdict is delegated to
 * {@see SlotAvailability}, and tenant scope comes from the explicit
 * `$restaurantId` with the global RestaurantScope bypassed, so the service
 * behaves identically from HTTP, a job, or the console.
 */
final class WaitlistBanner
{
    /** Performance cap: never evaluate more than this many waitlist entries. */
    private const int MAX_RESULTS = 20;

    public function __construct(
        private readonly SlotAvailability $availability,
    ) {}

    /**
     * Future-dated waitlisted reservations whose slot is not full, oldest wish
     * first. The query is capped before the availability filter runs, so the
     * result holds at most {@see self::MAX_RESULTS} entries.
     *
     * @return Collection<int, ReservationRequest>
     */
    public function eligibleNow(int $restaurantId): Collection
    {
        return ReservationRequest::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->where('status', ReservationStatus::Waitlisted)
            ->where('desired_at', '>=', now())
            ->orderBy('desired_at')
            ->limit(self::MAX_RESULTS)
            ->get()
            ->filter(fn (ReservationRequest $request): bool => $this->slotHasRoom($request))
            ->values();
    }

    private function slotHasRoom(ReservationRequest $request): bool
    {
        // The query already excludes null desired_at; the guard keeps the method
        // safe and explicit if it is ever called from another path.
        if ($request->desired_at === null) {
            return false;
        }

        $result = $this->availability->forSlot(
            $request->restaurant_id,
            CarbonImmutable::instance($request->desired_at),
            $request->party_size,
        );

        return $result->state !== SlotState::Full;
    }
}
