<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReservationReplyStatus;
use App\Enums\SendMode;
use App\Models\AutoSendAudit;
use App\Models\ReservationReply;
use App\Services\AI\AutoSendDecider;
use App\Services\AI\AutoSendDecision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends an auto-mode reply after the 60-second cancel window (PRD-007).
 *
 * Three things have to still hold by the time the job runs:
 *
 *   1. The reply is still in `scheduled_auto_send` — i.e., the operator
 *      hasn't cancelled or manually approved during the window.
 *   2. The restaurant is still in `auto` mode — killswitch hasn't fired.
 *   3. The hard-gate cascade still permits auto-send — state may have
 *      shifted (e.g., the requested time slipped under the lead-time
 *      threshold while we were waiting).
 *
 * Each of these is re-evaluated on `handle()`. Only when all three pass
 * do we delegate to `SendReservationReplyJob`. Any cancellation path
 * writes an `auto_send_audits` row so the operator dashboard can explain
 * why a scheduled send didn't go out.
 *
 * The cancel-window length (60 s) is set by the dispatcher (sister
 * issue #218) via `dispatch()->delay(...)`; this job carries no copy of
 * the constant.
 */
final class ScheduledAutoSendJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $replyId) {}

    public function handle(AutoSendDecider $decider): void
    {
        /** @var ReservationReply|null $reply */
        $reply = ReservationReply::withoutGlobalScopes()->find($this->replyId);
        if ($reply === null) {
            return;
        }

        // Race-condition guard. The status check is the very first thing
        // we do — if the operator cancelled or approved manually during
        // the window, the reply has already moved on and we must not
        // touch it.
        if ($reply->status !== ReservationReplyStatus::ScheduledAutoSend) {
            return;
        }

        $request = $reply->reservationRequest;
        $restaurant = $request?->restaurant;

        if ($restaurant === null || $restaurant->send_mode !== SendMode::Auto) {
            // Killswitch (or context loss) — cancel the schedule and
            // record why. No send goes out.
            $reply->forceFill(['status' => ReservationReplyStatus::CancelledAuto])->save();
            $this->writeAudit(
                $reply,
                AutoSendAudit::DECISION_CANCELLED_AUTO,
                AutoSendAudit::REASON_KILLSWITCH_DURING_WINDOW,
            );

            return;
        }

        $decision = $decider->decide($reply);
        if ($decision->decision !== AutoSendDecision::DECISION_AUTO_SEND) {
            // Hard-gate fires late. Drop back to draft so the operator
            // can take over via the V1.0 manual flow; the reason from
            // the decider is preserved in the audit.
            $reply->forceFill(['status' => ReservationReplyStatus::Draft])->save();
            $this->writeAudit(
                $reply,
                AutoSendAudit::DECISION_CANCELLED_AUTO,
                AutoSendAudit::REASON_HARD_GATE_LATE_PREFIX.$decision->reason,
            );

            return;
        }

        SendReservationReplyJob::dispatchSync($reply->id);
    }

    private function writeAudit(ReservationReply $reply, string $decision, string $reason): void
    {
        $request = $reply->reservationRequest;
        if ($request === null) {
            return;
        }

        AutoSendAudit::create([
            'reservation_reply_id' => $reply->id,
            'restaurant_id' => $request->restaurant_id,
            'send_mode' => SendMode::Auto->value,
            'decision' => $decision,
            'reason' => $reason,
            'triggered_by_user_id' => null,
            'created_at' => now(),
        ]);
    }
}
