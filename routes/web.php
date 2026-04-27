<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PublicReservationController;
use App\Http\Controllers\ReservationRequestController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('reservations/{reservation}', [ReservationRequestController::class, 'show'])
    ->whereNumber('reservation')
    ->middleware(['auth', 'verified'])
    ->name('reservations.show');

Route::post('reservations/bulk-status', [ReservationRequestController::class, 'bulkStatus'])
    ->middleware(['auth', 'verified'])
    ->name('reservations.bulk-status');

Route::get('r/{restaurant:slug}/reservations', [PublicReservationController::class, 'create'])
    ->name('public.reservations.create');

Route::post('r/{restaurant:slug}/reservations', [PublicReservationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('public.reservations.store');

Route::get('r/{restaurant:slug}/reservations/thanks', [PublicReservationController::class, 'thanks'])
    ->name('public.reservations.thanks');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
