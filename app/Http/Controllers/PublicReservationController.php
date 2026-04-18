<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Events\ReservationRequestReceived;
use App\Http\Requests\StoreReservationRequest;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

final class PublicReservationController extends Controller
{
    public function create(Restaurant $restaurant): Response
    {
        return Inertia::render('Public/ReservationForm', [
            'restaurant' => [
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'tonality' => $restaurant->tonality->value,
            ],
        ]);
    }

    public function store(StoreReservationRequest $request, Restaurant $restaurant): RedirectResponse
    {
        if ($request->filled('website')) {
            return redirect()->route('public.reservations.thanks', $restaurant);
        }

        $validated = $request->validated();

        $reservationRequest = ReservationRequest::create([
            'restaurant_id' => $restaurant->id,
            'source' => ReservationSource::WebForm,
            'status' => ReservationStatus::New,
            'guest_name' => $validated['guest_name'],
            'guest_email' => $validated['guest_email'],
            'guest_phone' => $validated['guest_phone'] ?? null,
            'party_size' => $validated['party_size'],
            'desired_at' => Carbon::parse($validated['desired_at'], $restaurant->timezone)->utc(),
            'message' => $validated['message'] ?? null,
            'raw_payload' => $validated,
        ]);

        ReservationRequestReceived::dispatch($reservationRequest);

        return redirect()->route('public.reservations.thanks', $restaurant);
    }

    public function thanks(Restaurant $restaurant): Response
    {
        return Inertia::render('Public/Thanks', [
            'restaurant' => [
                'name' => $restaurant->name,
            ],
        ]);
    }
}
