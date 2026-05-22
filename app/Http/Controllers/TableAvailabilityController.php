<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\TableAvailabilityRequest;
use App\Http\Resources\TableResource;
use App\Models\Table;
use App\Services\Availability\DTOs\SlotAvailabilityResult;
use App\Services\Availability\SlotAvailability;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the Belegung (occupancy) tab of the tables page (PRD-011): a
 * deterministic day grid produced by {@see SlotAvailability::forDay}. The
 * controller stays a thin Inertia adapter — availability is computed by the
 * service, tenant scope comes from the Table model's global RestaurantScope, and
 * the `can:viewAny,Table` middleware gates access.
 */
final class TableAvailabilityController extends Controller
{
    public function __construct(
        private readonly SlotAvailability $availability,
    ) {}

    public function show(TableAvailabilityRequest $request): Response
    {
        $user = $request->user();
        $restaurant = $user->restaurant;
        $date = CarbonImmutable::parse($request->validated()['date']);

        $day = $this->availability->forDay($user->restaurant_id, $date);

        $tables = Table::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tables', [
            'tables' => TableResource::collection($tables),
            'activeTab' => 'availability',
            'availability' => [
                'date' => $day->date->toDateString(),
                'total_capacity' => $day->totalCapacity,
                'reserved_seats' => $day->reservedSeats,
                // slotStart is a UTC instant; render it in the restaurant's own
                // timezone so the operator reads local opening times.
                'slots' => $day->slots->map(fn (SlotAvailabilityResult $slot): array => [
                    'time' => $slot->slotStart->setTimezone($restaurant->timezone)->format('H:i'),
                    'state' => $slot->state->value,
                    'suggested_table_id' => $slot->suggestedTableId,
                ])->values()->all(),
            ],
        ]);
    }
}
