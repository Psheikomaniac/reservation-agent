<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GdprDeleteRequest;
use App\Models\GdprAudit;
use App\Models\ReservationRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public, login-less GDPR self-service (PRD-015, Art. 15 + 17). Every route
 * is `signed`: the link in each confirmation mail carries a 30-day signature
 * tied to the specific reservation id, so there is no auth user and no
 * cross-tenant exposure — a signature for restaurant A's reservation cannot
 * be replayed against restaurant B's id.
 *
 * Each action records a PII-free {@see GdprAudit} row (the only trace kept);
 * guest data itself is never logged.
 */
final class GdprSelfServiceController extends Controller
{
    /** Short confirm window for the destructive delete step (PRD-015). */
    private const int DELETE_TOKEN_TTL_MINUTES = 15;

    public function show(ReservationRequest $reservation): Response
    {
        GdprAudit::record(GdprAudit::ACTION_VIEW, $reservation->restaurant_id);

        return Inertia::render('Public/GdprSelfService', [
            'reservation' => [
                'guest_name' => $reservation->guest_name,
                'guest_email' => $reservation->guest_email,
                'guest_phone' => $reservation->guest_phone,
                'party_size' => $reservation->party_size,
                'status' => $reservation->status->value,
                'note' => $reservation->note,
                'desired_at' => $reservation->desired_at?->toIso8601String(),
                'created_at' => $reservation->created_at?->toIso8601String(),
            ],
            'restaurant' => [
                'name' => $reservation->restaurant->name,
                'timezone' => $reservation->restaurant->timezone,
            ],
            // Short-lived signed token for the confirm step — a deliberately
            // tighter window than the 30-day view link.
            'deleteToken' => URL::temporarySignedRoute(
                'gdpr.self-service.delete',
                now()->addMinutes(self::DELETE_TOKEN_TTL_MINUTES),
                ['reservation' => $reservation->id],
            ),
        ]);
    }

    public function delete(GdprDeleteRequest $request, ReservationRequest $reservation): Response|RedirectResponse
    {
        // Anti-bot confirm: the typed date must equal the reservation date (in
        // the restaurant's timezone — the date the guest actually booked, and
        // the one the page shows them).
        $expected = $this->expectedConfirmDate($reservation);
        if ($expected === null || $request->validated('confirm_date') !== $expected) {
            return back()->withErrors([
                'confirm_date' => 'Das eingegebene Datum stimmt nicht mit der Reservierung überein.',
            ]);
        }

        // Capture tenant context before the row is gone (no PII is kept).
        $restaurantId = $reservation->restaurant_id;
        $restaurantName = $reservation->restaurant->name;

        DB::transaction(function () use ($reservation): void {
            $reservation->messages()->delete();
            $reservation->replies()->delete();
            $reservation->tableAssignments()->delete();
            $reservation->delete();
        });

        GdprAudit::record(GdprAudit::ACTION_DELETE, $restaurantId);

        return Inertia::render('Public/GdprDeleted', [
            'restaurant' => [
                'name' => $restaurantName,
            ],
        ]);
    }

    /**
     * The reservation date the guest must type to confirm deletion, in the
     * restaurant timezone (`d.m.Y`). Null when the reservation has no desired
     * time — confirmation then cannot match, so the data is left untouched.
     */
    private function expectedConfirmDate(ReservationRequest $reservation): ?string
    {
        if ($reservation->desired_at === null) {
            return null;
        }

        return CarbonImmutable::instance($reservation->desired_at)
            ->setTimezone($reservation->restaurant->timezone)
            ->format('d.m.Y');
    }
}
