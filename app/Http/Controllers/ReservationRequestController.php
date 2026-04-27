<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

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
}
