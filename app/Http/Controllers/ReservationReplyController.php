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

        // Promotion path from `shadow` → `approved` (PRD-007 issue
        // #221): capturing whether the operator changed the AI text
        // before sending feeds the takeover-rate stat exposed by
        // PRD-008. Strict trimmed comparison so a whitespace-only
        // diff is not counted as a real edit.
        $wasShadow = $reply->status === ReservationReplyStatus::Shadow;
        $editedBody = $request->string('body')->trim()->toString();
        $bodyChanged = $editedBody !== '' && $editedBody !== trim($reply->body);

        if ($bodyChanged) {
            $reply->body = $editedBody;
        }

        $payload = [
            'status' => ReservationReplyStatus::Approved,
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ];

        if ($wasShadow) {
            $payload['shadow_was_modified'] = $bodyChanged;
            // The operator demonstrably reviewed the draft when they
            // hit Approve; mark it compared if the side-effect
            // endpoint hasn't already done so.
            if ($reply->shadow_compared_at === null) {
                $payload['shadow_compared_at'] = now();
            }
        }

        $reply->forceFill($payload)->save();

        SendReservationReplyJob::dispatch($reply->id);

        return back()->with('success', 'Antwort freigegeben — sie wird gleich versendet.');
    }

    /**
     * Mark a shadow reply as "the operator has reviewed this" so the
     * PRD-008 takeover-rate denominator (`shadow_compared_at IS NOT
     * NULL`) counts it. Idempotent: re-opening the drawer must not
     * overwrite the timestamp; the front-end relies on
     * `shadow_compared_at` being set the moment the operator first
     * looked at the draft. Only applies to replies whose current
     * status is still `shadow` — once promoted to `approved`, the
     * approve flow itself sets the timestamp.
     */
    public function markShadowCompared(ReservationReply $reply): RedirectResponse
    {
        if ($reply->status !== ReservationReplyStatus::Shadow) {
            return back();
        }

        if ($reply->shadow_compared_at !== null) {
            return back();
        }

        $reply->forceFill(['shadow_compared_at' => now()])->save();

        return back();
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
