<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ReservationRequestDetailResource;
use App\Models\ReservationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ReservationRequestController extends Controller
{
    public function show(Request $request, int $reservation): Response
    {
        $reservationRequest = ReservationRequest::query()
            ->withoutGlobalScopes()
            ->findOrFail($reservation);

        Gate::authorize('view', $reservationRequest);

        return Inertia::render('Reservations/Show', [
            'reservation' => (new ReservationRequestDetailResource($reservationRequest))
                ->toArray($request),
        ]);
    }
}
