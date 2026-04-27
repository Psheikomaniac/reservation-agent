<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\BulkStatusRequest;
use App\Models\ReservationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class ReservationRequestController extends Controller
{
    /**
     * Deep-link redirect into the dashboard detail drawer. The drawer
     * (DashboardController@index with `?selected={id}`) handles policy
     * enforcement: invalid or foreign-tenant ids resolve to a null
     * `selectedRequest` prop so the drawer simply does not open.
     */
    public function show(int $reservation): RedirectResponse
    {
        return redirect()->route('dashboard', ['selected' => $reservation]);
    }

    /**
     * Apply a single target status to a batch of reservations. Each id is
     * checked individually – ids that fail the per-id `update` policy or
     * cannot legally transition to the target status are reported back as
     * `skipped` instead of aborting the whole batch. The actual mutation is
     * a single bulk `update()` to keep the write path O(1) regardless of
     * batch size.
     */
    public function bulkStatus(BulkStatusRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        /** @var list<int> $requestedIds */
        $requestedIds = array_map('intval', $validated['ids']);
        $targetStatus = ReservationStatus::from($validated['status']);

        $reservations = ReservationRequest::query()
            ->whereIn('id', $requestedIds)
            ->get(['id', 'restaurant_id', 'status']);

        $loadedIds = $reservations->pluck('id')->all();
        $skipped = array_values(array_diff($requestedIds, $loadedIds));

        $updatableIds = [];
        foreach ($reservations as $reservation) {
            $allowedByPolicy = Gate::forUser($request->user())
                ->allows('update', $reservation);

            if (! $allowedByPolicy || ! $reservation->status->canTransitionTo($targetStatus)) {
                $skipped[] = $reservation->id;

                continue;
            }

            $updatableIds[] = $reservation->id;
        }

        $updated = 0;
        if ($updatableIds !== []) {
            $updated = ReservationRequest::query()
                ->whereIn('id', $updatableIds)
                ->update([
                    'status' => $targetStatus->value,
                    'updated_at' => now(),
                ]);
        }

        sort($skipped);

        return back()->with('bulkStatus', [
            'updated' => $updated,
            'skipped' => array_values($skipped),
        ]);
    }
}
