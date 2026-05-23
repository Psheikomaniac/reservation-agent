<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GdprSelfServiceController;
use App\Http\Controllers\PublicReservationController;
use App\Http\Controllers\QuickReservationController;
use App\Http\Controllers\ReservationMessagesController;
use App\Http\Controllers\ReservationReplyController;
use App\Http\Controllers\ReservationRequestController;
use App\Http\Controllers\SendModeKillswitchController;
use App\Http\Controllers\TableAvailabilityController;
use App\Http\Controllers\TableController;
use App\Models\Table;
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

Route::post('exports', [ExportController::class, 'store'])
    ->middleware(['auth', 'verified'])
    ->name('exports.store');

Route::get('exports/download/{token}', [ExportController::class, 'download'])
    ->whereNumber('token')
    ->middleware(['auth', 'verified', 'signed'])
    ->name('exports.download');

// Static segment registered before the {reservation} wildcard (static-before-
// wildcard convention, mirroring tables/availability vs tables/{table}).
Route::get('reservations/quick', [QuickReservationController::class, 'create'])
    ->middleware(['auth', 'verified', 'can:viewAny,'.Table::class])
    ->name('reservations.quick.create');

Route::post('reservations/quick', [QuickReservationController::class, 'store'])
    ->middleware(['auth', 'verified', 'can:viewAny,'.Table::class])
    ->name('reservations.quick.store');

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

Route::post('reservation-replies/{reply}/mark-shadow-compared', [ReservationReplyController::class, 'markShadowCompared'])
    ->middleware(['auth', 'verified', 'can:markShadowCompared,reply'])
    ->name('reservation-replies.mark-shadow-compared');

Route::post('restaurants/{restaurant}/send-mode/killswitch', SendModeKillswitchController::class)
    ->middleware(['auth', 'verified', 'can:manageSendMode,restaurant'])
    ->name('restaurants.send-mode.killswitch');

Route::get('tables/availability', [TableAvailabilityController::class, 'show'])
    ->middleware(['auth', 'verified', 'can:viewAny,'.Table::class])
    ->name('tables.availability');

Route::get('tables', [TableController::class, 'index'])
    ->middleware(['auth', 'verified', 'can:viewAny,'.Table::class])
    ->name('tables.index');

Route::post('tables', [TableController::class, 'store'])
    ->middleware(['auth', 'verified', 'can:create,'.Table::class])
    ->name('tables.store');

Route::match(['put', 'patch'], 'tables/{table}', [TableController::class, 'update'])
    ->whereNumber('table')
    ->middleware(['auth', 'verified', 'can:update,table'])
    ->name('tables.update');

Route::delete('tables/{table}', [TableController::class, 'destroy'])
    ->whereNumber('table')
    ->middleware(['auth', 'verified', 'can:delete,table'])
    ->name('tables.destroy');

Route::get('r/{restaurant:slug}/reservations', [PublicReservationController::class, 'create'])
    ->name('public.reservations.create');

Route::post('r/{restaurant:slug}/reservations', [PublicReservationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('public.reservations.store');

Route::get('r/{restaurant:slug}/reservations/thanks', [PublicReservationController::class, 'thanks'])
    ->name('public.reservations.thanks');

// Public, login-less GDPR self-service (PRD-015). Signed link from the
// confirmation mail; the signature is the only guard (no auth, scoped by id).
Route::get('gdpr/{reservation}', [GdprSelfServiceController::class, 'show'])
    ->whereNumber('reservation')
    ->middleware('signed')
    ->name('gdpr.self-service');

Route::post('gdpr/{reservation}/delete', [GdprSelfServiceController::class, 'delete'])
    ->whereNumber('reservation')
    ->middleware('signed')
    ->name('gdpr.self-service.delete');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
