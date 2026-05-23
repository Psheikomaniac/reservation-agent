<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\BulkGdprDeleteRequest;
use App\Http\Requests\BulkStatusRequest;
use App\Models\GdprAudit;
use App\Models\ReservationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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

    /**
     * GDPR Art. 17 owner bulk-delete (PRD-015): hard-delete every reservation
     * with the given guest email — plus its messages, replies and table
     * assignments — in one transaction, and write a single PII-free
     * `owner_bulk_delete` audit row.
     *
     * Matching is on the EXACT email (not the dashboard's name-or-email LIKE
     * search) so an erasure request never sweeps up an unrelated guest. Tenant
     * scope is the global RestaurantScope (authenticated owner), so only the
     * owner's own restaurant is touched; authorization is the `manage` policy
     * enforced in {@see BulkGdprDeleteRequest}.
     */
    public function bulkGdprDelete(BulkGdprDeleteRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $restaurantId = $user->restaurant_id;
        $email = $request->validated('email');

        $reservations = ReservationRequest::query()
            ->where('guest_email', $email)
            ->get();

        DB::transaction(function () use ($reservations): void {
            foreach ($reservations as $reservation) {
                $reservation->messages()->delete();
                $reservation->replies()->delete();
                $reservation->tableAssignments()->delete();
                $reservation->delete();
            }
        });

        GdprAudit::record(GdprAudit::ACTION_OWNER_BULK_DELETE, $restaurantId);

        return back()->with('success', $reservations->count().' Reservierung(en) DSGVO-konform gelöscht.');
    }
}
