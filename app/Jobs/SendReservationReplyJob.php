<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationStatus;
use App\Mail\ReservationReplyMail;
use App\Models\ReservationReply;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sole sender of approved reservation replies (PRD-005). Picks up a
 * ReservationReply by id, mails the operator-approved body to the guest,
 * and transitions both the reply and its parent request on success.
 *
 * On any failure: the reply is marked `failed` with the trimmed exception
 * message stored in `error_message`. The dashboard surfaces this so the
 * operator can retry or compose a manual reply.
 *
 * Retry policy: explicit and capped — three attempts with growing backoff
 * (matches the existing `FetchReservationEmailsJob`). The last attempt
 * routes through `failed()` so the dashboard always reflects a deterministic
 * end state.
 */
final class SendReservationReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $reservationReplyId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(): void
    {
        /** @var ReservationReply|null $reply */
        $reply = ReservationReply::withoutGlobalScopes()->find($this->reservationReplyId);
        if ($reply === null) {
            return;
        }

        // Guard against a re-dispatch landing on an already-sent reply.
        if (in_array($reply->status, [ReservationReplyStatus::Sent, ReservationReplyStatus::Failed], true)) {
            return;
        }

        $reservationRequest = $reply->reservationRequest;
        $email = $reservationRequest?->guest_email;

        if ($email === null || $email === '') {
            $this->markFailed($reply, 'Guest email is missing.');

            return;
        }

        try {
            Mail::to($email)->send(new ReservationReplyMail($reply));

            $reply->forceFill([
                'status' => ReservationReplyStatus::Sent,
                'sent_at' => now(),
                'error_message' => null,
            ])->save();

            // Transition the parent request via forceFill so the AI/approval
            // pipeline isn't blocked by the manual-status state machine
            // (PRD-005: send success → request status = replied).
            $reservationRequest->forceFill(['status' => ReservationStatus::Replied])->save();
        } catch (Throwable $e) {
            $this->markFailed($reply, $e->getMessage());

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        /** @var ReservationReply|null $reply */
        $reply = ReservationReply::withoutGlobalScopes()->find($this->reservationReplyId);
        if ($reply === null) {
            return;
        }

        $this->markFailed($reply, $exception->getMessage());
    }

    private function markFailed(ReservationReply $reply, string $message): void
    {
        $reply->forceFill([
            'status' => ReservationReplyStatus::Failed,
            'error_message' => trim($message),
        ])->save();
    }
}
