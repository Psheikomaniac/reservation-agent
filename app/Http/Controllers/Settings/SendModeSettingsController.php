<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SendMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SendModeSettingsUpdateRequest;
use App\Models\ReservationReply;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-only settings surface for the auto-send pipeline (PRD-007).
 *
 * `edit` renders the three-card SendMode page with the current
 * configuration plus shadow-mode takeover statistics over the last 30
 * days, so the confirmation dialog for `shadow → auto` can show the
 * real takeover-rate figure the operator built up.
 *
 * `update` validates and persists the new mode and the hard-gate
 * thresholds in a single transaction-friendly model save. Mode flips
 * snapshot the actor and timestamp; the killswitch endpoint
 * (`SendModeKillswitchController`) handles the panic-stop case
 * separately so this surface stays focused on configuration.
 */
class SendModeSettingsController extends Controller
{
    /**
     * Window the dashboard summarises in the confirmation dialog.
     */
    private const int SHADOW_STATS_WINDOW_DAYS = 30;

    public function edit(): Response
    {
        /** @var User $user */
        $user = Auth::user();
        $restaurant = $user->restaurant;
        if ($restaurant === null) {
            abort(403);
        }

        Gate::authorize('manageSendMode', $restaurant);

        return Inertia::render('settings/SendMode', [
            'restaurantId' => $restaurant->id,
            'sendMode' => $restaurant->send_mode->value,
            'partySizeMax' => $restaurant->auto_send_party_size_max,
            'minLeadTimeMinutes' => $restaurant->auto_send_min_lead_time_minutes,
            'sendModeChangedAt' => $restaurant->send_mode_changed_at?->toIso8601String(),
            'shadowStats' => $this->shadowStats($restaurant),
        ]);
    }

    public function update(SendModeSettingsUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $restaurant = $user->restaurant;
        if ($restaurant === null) {
            abort(403);
        }

        Gate::authorize('manageSendMode', $restaurant);

        $newMode = SendMode::from($request->validated('send_mode'));
        $modeChanged = $restaurant->send_mode !== $newMode;

        $payload = [
            'send_mode' => $newMode,
            'auto_send_party_size_max' => $request->validated('auto_send_party_size_max'),
            'auto_send_min_lead_time_minutes' => $request->validated('auto_send_min_lead_time_minutes'),
        ];

        if ($modeChanged) {
            $payload['send_mode_changed_at'] = now();
            $payload['send_mode_changed_by'] = $user->id;
        }

        $restaurant->update($payload);

        return back()->with('success', 'Versand-Einstellungen gespeichert.');
    }

    /**
     * Aggregate the last 30 days of shadow-mode replies into the figures
     * the confirmation dialog displays before the operator commits to
     * `auto`. `taken_over` counts replies the operator promoted from
     * `shadow → approved` without editing the body, i.e., where the AI
     * draft was good enough to ship verbatim.
     *
     * @return array{total: int, takenOver: int, takeoverRate: float|null, hasData: bool}
     */
    private function shadowStats(Restaurant $restaurant): array
    {
        $since = Carbon::now()->subDays(self::SHADOW_STATS_WINDOW_DAYS);

        $shadowReplies = ReservationReply::query()
            ->whereHas(
                'reservationRequest',
                fn ($query) => $query->where('restaurant_id', $restaurant->id),
            )
            ->where('send_mode_at_creation', SendMode::Shadow->value)
            ->where('created_at', '>=', $since);

        $total = (clone $shadowReplies)->count();
        $takenOver = (clone $shadowReplies)->where('shadow_was_modified', false)
            ->whereNotNull('shadow_compared_at')
            ->count();

        return [
            'total' => $total,
            'takenOver' => $takenOver,
            'takeoverRate' => $total > 0 ? round($takenOver / $total, 4) : null,
            'hasData' => $total > 0,
        ];
    }
}
