<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PublicReservationController;
use App\Http\Controllers\ReservationMessagesController;
use App\Http\Controllers\ReservationReplyController;
use App\Http\Controllers\ReservationRequestController;
use App\Http\Controllers\SendModeKillswitchController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('analytics', [AnalyticsController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('analytics.index');

Route::get('reservations/{reservation}', [ReservationRequestController::class, 'show'])
    ->whereNumber('reservation')
    ->middleware(['auth', 'verified'])
    ->name('reservations.show');

Route::get('reservations/{reservation}/messages', [ReservationMessagesController::class, 'index'])
    ->whereNumber('reservation')
    ->middleware(['auth', 'verified'])
    ->name('reservations.messages.index');

Route::post('reservations/bulk-status', [ReservationRequestController::class, 'bulkStatus'])
    ->middleware(['auth', 'verified'])
    ->name('reservations.bulk-status');

Route::post('reservation-replies/{reply}/approve', [ReservationReplyController::class, 'approve'])
    ->middleware(['auth', 'verified', 'can:approve,reply'])
    ->name('reservation-replies.approve');

Route::post('reservation-replies/{reply}/cancel-auto-send', [ReservationReplyController::class, 'cancelAutoSend'])
    ->middleware(['auth', 'verified', 'can:cancelAutoSend,reply'])
    ->name('reservation-replies.cancel-auto-send');

Route::post('restaurants/{restaurant}/send-mode/killswitch', SendModeKillswitchController::class)
    ->middleware(['auth', 'verified', 'can:manageSendMode,restaurant'])
    ->name('restaurants.send-mode.killswitch');

Route::get('r/{restaurant:slug}/reservations', [PublicReservationController::class, 'create'])
    ->name('public.reservations.create');

Route::post('r/{restaurant:slug}/reservations', [PublicReservationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('public.reservations.store');

Route::get('r/{restaurant:slug}/reservations/thanks', [PublicReservationController::class, 'thanks'])
    ->name('public.reservations.thanks');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
