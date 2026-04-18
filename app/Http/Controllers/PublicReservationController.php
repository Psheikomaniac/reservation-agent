<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
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
            ],
        ]);
    }

    public function store(Restaurant $restaurant): RedirectResponse
    {
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
