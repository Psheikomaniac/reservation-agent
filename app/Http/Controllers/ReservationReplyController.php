<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ReservationReplyStatus;
use App\Http\Requests\ApproveReservationReplyRequest;
use App\Jobs\SendReservationReplyJob;
use App\Models\AutoSendAudit;
use App\Models\ReservationReply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ReservationReplyController extends Controller
{
    /**
     * Approve an AI draft and queue it for sending (PRD-005).
     *
     * Idempotency: re-approving an already-Sent or already-Approved reply
     * is a no-op with a flash error — the dashboard's "Freigeben" button
     * is disabled in those states, but a stale tab can still POST.
     */
    public function approve(ApproveReservationReplyRequest $request, ReservationReply $reply): RedirectResponse
    {
        if (in_array($reply->status, [ReservationReplyStatus::Sent, ReservationReplyStatus::Approved], true)) {
            return back()->with('error', 'Diese Antwort wurde bereits freigegeben.');
        }

        $editedBody = $request->string('body')->trim()->toString();
        if ($editedBody !== '' && $editedBody !== $reply->body) {
            $reply->body = $editedBody;
        }

        $reply->forceFill([
            'status' => ReservationReplyStatus::Approved,
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ])->save();

        SendReservationReplyJob::dispatch($reply->id);

        return back()->with('success', 'Antwort freigegeben — sie wird gleich versendet.');
    }

    /**
     * Cancel a still-scheduled auto-send during its 60-second cancel
     * window (PRD-007). The actual send hasn't gone out yet — the
     * `ScheduledAutoSendJob` will see the status flip when it wakes up
     * and silently no-op (race-condition guard in #217).
     */
    public function cancelAutoSend(ReservationReply $reply): RedirectResponse
    {
        if ($reply->status !== ReservationReplyStatus::ScheduledAutoSend) {
            return back()->with('error', 'Diese Antwort ist nicht im Auto-Versand-Fenster.');
        }

        $reply->forceFill(['status' => ReservationReplyStatus::CancelledAuto])->save();

        AutoSendAudit::write(
            $reply,
            AutoSendAudit::DECISION_CANCELLED_AUTO,
            'cancelled_by_owner',
            Auth::id(),
        );

        return back()->with('success', 'Versand abgebrochen.');
    }
}
