<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReservationRequest;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ReservationRequestController extends Controller
{
    public function show(int $reservation): Response
    {
        $reservationRequest = ReservationRequest::query()
            ->withoutGlobalScopes()
            ->findOrFail($reservation);

        Gate::authorize('view', $reservationRequest);

        return Inertia::render('Reservations/Show', [
            'reservation' => [
                'id' => $reservationRequest->id,
                'guest_name' => $reservationRequest->guest_name,
                'guest_email' => $reservationRequest->guest_email,
                'party_size' => $reservationRequest->party_size,
                'desired_at' => $reservationRequest->desired_at?->toIso8601String(),
                'status' => $reservationRequest->status->value,
                'source' => $reservationRequest->source->value,
                'message' => $reservationRequest->message,
            ],
        ]);
    }
}
