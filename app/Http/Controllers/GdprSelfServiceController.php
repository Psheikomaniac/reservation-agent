<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GdprAudit;
use App\Models\ReservationRequest;
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
        ]);
    }
}
