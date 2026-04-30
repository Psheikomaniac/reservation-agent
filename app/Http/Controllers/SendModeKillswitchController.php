<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationReplyStatus;
use App\Enums\SendMode;
use App\Models\AutoSendAudit;
use App\Models\ReservationReply;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Owner-driven instant-stop for the auto-send pipeline (PRD-007 §
 * Killswitch). Flips the restaurant back to `manual` and cancels every
 * reply that's still inside the cancel window in a single transaction
 * — so a stale `ScheduledAutoSendJob` waking up after the click cannot
 * find a reply to send.
 *
 * The cancel pass writes one `auto_send_audits` row per reply with
 * `triggered_by_user_id = auth()->id()` so the dashboard can attribute
 * the action; the lurking `ScheduledAutoSendJob` race is covered by
 * that job's own re-check of `restaurant->send_mode` (#217).
 */
class SendModeKillswitchController extends Controller
{
    public function __invoke(Restaurant $restaurant): RedirectResponse
    {
        DB::transaction(function () use ($restaurant): void {
            $restaurant->update([
                'send_mode' => SendMode::Manual,
                'send_mode_changed_at' => now(),
                'send_mode_changed_by' => Auth::id(),
            ]);

            $scheduled = ReservationReply::query()
                ->whereHas(
                    'reservationRequest',
                    fn ($query) => $query->where('restaurant_id', $restaurant->id),
                )
                ->where('status', ReservationReplyStatus::ScheduledAutoSend)
                ->get();

            foreach ($scheduled as $reply) {
                $reply->forceFill(['status' => ReservationReplyStatus::CancelledAuto])->save();

                AutoSendAudit::write(
                    $reply,
                    AutoSendAudit::DECISION_CANCELLED_AUTO,
                    'killswitch',
                    Auth::id(),
                );
            }
        });

        return back()->with(
            'success',
            'Auto-Versand sofort gestoppt. Alle ausstehenden Versandvorgänge wurden abgebrochen.'
        );
    }
}
