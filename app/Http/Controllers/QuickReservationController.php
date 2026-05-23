<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\QuickReservationCreateRequest;
use App\Http\Requests\QuickReservationStoreRequest;
use App\Http\Resources\TableResource;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use App\Services\Availability\SlotAvailability;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quick phone/walk-in reservation entry (PRD-012).
 *
 * `create` is the read path: it renders the keyboard-first form and, on every
 * date/time/party change, recomputes a deterministic availability verdict via
 * {@see SlotAvailability::forSlot}. `store` persists the reservation directly as
 * `confirmed` (the owner has already confirmed verbally) with an auto-assigned
 * table — no AI reply, no mail.
 *
 * The operator works in the restaurant's local time, so smart defaults and the
 * `defaults` prop are local, while the availability service receives the UTC
 * instant it expects (matching how reservations store `desired_at`). The
 * controller stays a thin Inertia adapter — availability is the service's job,
 * authorization is the route's `can:viewAny,Table` gate (TablePolicy, which also
 * rejects a user without a restaurant, like the tables.availability endpoint),
 * and tenant scope comes from the Table model's global RestaurantScope.
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

    public function store(QuickReservationStoreRequest $request): RedirectResponse
    {
        $user = $request->user();
        $restaurant = $user->restaurant;
        $validated = $request->validated();

        // Shared helper (used by the public reservation path too) parses the
        // operator's local input and returns the UTC instant reservations store.
        $datetime = Timezone::localToUtc(
            "{$validated['date']} {$validated['time']}",
            $restaurant->timezone,
        )->toImmutable();

        DB::transaction(function () use ($user, $validated, $datetime): void {
            // Serialize concurrent bookings for this slot: a SELECT ... FOR UPDATE
            // on the restaurant's active tables makes a second request block until
            // this one commits, after which its in-lock availability re-check sees
            // the assignment just written. (lockForUpdate is a no-op on SQLite but
            // protects the production MySQL/Postgres path.) Tenant scope is the
            // Table model's global RestaurantScope.
            Table::query()
                ->where('active', true)
                ->lockForUpdate()
                ->get();

            $tableIds = $this->resolveTableIds($user->restaurant_id, $datetime, $validated);

            $reservation = ReservationRequest::create([
                'restaurant_id' => $user->restaurant_id,
                'source' => $validated['source'],
                'status' => ReservationStatus::Confirmed,
                'guest_name' => $validated['guest_name'],
                'guest_phone' => $validated['guest_phone'] ?? null,
                'guest_email' => $validated['guest_email'] ?? null,
                'party_size' => $validated['party_size'],
                'desired_at' => $datetime,
                'note' => $validated['note'] ?? null,
                'created_by_user_id' => $user->id,
            ]);

            foreach ($tableIds as $tableId) {
                ReservationTableAssignment::create([
                    'reservation_request_id' => $reservation->id,
                    'table_id' => $tableId,
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $user->id,
                ]);
            }
        });

        return redirect()->route('dashboard')->with('success', 'Reservierung gespeichert.');
    }

    /**
     * Resolve the table(s) to assign. A manual `table_id` is honoured — yielding
     * exactly that one table — as long as it is actually free in the slot (the
     * operator may deliberately seat a regular at a specific table). Otherwise
     * the smallest fitting single table or two-table combination is auto-assigned;
     * a combination returns one id per table so both are marked occupied (the M:N
     * schema, PRD-011) — assigning only the primary would leave the second table
     * double-bookable. Throws when the chosen table is busy or no table fits,
     * surfacing a clear message instead of a silent failure.
     *
     * @param  array<string, mixed>  $validated
     * @return list<int>
     */
    private function resolveTableIds(int $restaurantId, CarbonImmutable $datetime, array $validated): array
    {
        if (isset($validated['table_id'])) {
            $tableId = (int) $validated['table_id'];

            $isFree = $this->availability
                ->freeTablesAt($restaurantId, $datetime)
                ->pluck('id')
                ->contains($tableId);

            if (! $isFree) {
                throw ValidationException::withMessages([
                    'table_id' => 'Dieser Tisch ist im gewählten Zeitfenster bereits belegt.',
                ]);
            }

            return [$tableId];
        }

        $combination = $this->availability
            ->suggestTableCombination($restaurantId, $datetime, (int) $validated['party_size']);

        if ($combination === null) {
            throw ValidationException::withMessages([
                'table_id' => 'Kein passender Tisch verfügbar. Bitte anderen Slot wählen.',
            ]);
        }

        return $combination->tableIds;
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
