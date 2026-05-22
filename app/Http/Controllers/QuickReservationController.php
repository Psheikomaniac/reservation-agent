<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\QuickReservationCreateRequest;
use App\Http\Resources\TableResource;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use App\Services\Availability\SlotAvailability;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quick phone/walk-in reservation entry (PRD-012). This controller serves the
 * read path: it renders the keyboard-first form and, on every date/time/party
 * change, recomputes a deterministic availability verdict via
 * {@see SlotAvailability::forSlot}. The store path lives in #343.
 *
 * The operator works in the restaurant's local time, so smart defaults and the
 * `defaults` prop are local, while `forSlot` receives the UTC instant it expects
 * (matching how reservations store `desired_at`). The controller stays a thin
 * Inertia adapter — availability is the service's job, authorization is the
 * route's `can:viewAny,Table` gate (TablePolicy, which also rejects a user
 * without a restaurant, like the tables.availability endpoint), and tenant scope
 * comes from the Table model's global RestaurantScope.
 */
final class QuickReservationController extends Controller
{
    private const int DEFAULT_PARTY_SIZE = 2;

    public function __construct(
        private readonly SlotAvailability $availability,
    ) {}

    public function create(QuickReservationCreateRequest $request): Response
    {
        $user = $request->user();
        $restaurant = $user->restaurant;
        $validated = $request->validated();

        // The operator types local time; default to "now, rounded up to the next
        // 30 minutes, plus an hour" so the form opens on a plausible near slot.
        // date and time default independently so a partially supplied query never
        // silently drops the field that was given (the live preview sends both).
        $base = CarbonImmutable::now($restaurant->timezone)->ceilMinutes(30)->addHour();
        $local = CarbonImmutable::parse(
            ($validated['date'] ?? $base->format('Y-m-d')).' '.($validated['time'] ?? $base->format('H:i')),
            $restaurant->timezone,
        );

        $partySize = (int) ($validated['party_size'] ?? self::DEFAULT_PARTY_SIZE);

        $availability = $this->availability->forSlot(
            $user->restaurant_id,
            $local->utc(),
            $partySize,
        );

        $tables = Table::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Reservations/Quick', [
            'tables' => TableResource::collection($tables),
            'defaults' => [
                'date' => $local->format('Y-m-d'),
                'time' => $local->format('H:i'),
                'party_size' => $partySize,
            ],
            'availability' => $this->presentAvailability($availability, $restaurant),
        ]);
    }

    /**
     * Shape the availability verdict for the status banner. The suggested table
     * (or two-table combination) is referenced by id so the page can resolve the
     * label from the `tables` prop; alternative slots are rendered in the
     * restaurant timezone as date+time so a click can refill both fields.
     *
     * @return array<string, mixed>
     */
    private function presentAvailability(SlotAvailabilityResult $result, Restaurant $restaurant): array
    {
        return [
            'state' => $result->state->value,
            'suggested_table_id' => $result->suggestedTableId,
            'combination' => $result->combination === null ? null : [
                'primary_table_id' => $result->combination->primaryTableId,
                'table_ids' => $result->combination->tableIds,
                'total_seats' => $result->combination->totalSeats,
            ],
            'alternative_slots' => $result->alternativeSlots
                ->map(fn (CarbonImmutable $candidate): array => [
                    'date' => $candidate->setTimezone($restaurant->timezone)->format('Y-m-d'),
                    'time' => $candidate->setTimezone($restaurant->timezone)->format('H:i'),
                ])
                ->values()
                ->all(),
        ];
    }
}
