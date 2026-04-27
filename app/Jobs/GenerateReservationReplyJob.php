<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReservationReplyStatus;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Services\AI\Contracts\ReplyGenerator;
use App\Services\AI\OpenAiReplyGenerator;
use App\Services\AI\ReservationContextBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the deterministic Context-JSON for a reservation request and
 * persists the AI-drafted reply (PRD-005).
 *
 * On the happy path: the OpenAI generator returns a string, the reply is
 * saved as `draft`, the snapshot retains the exact JSON sent to OpenAI.
 *
 * On any failure inside this job — dependency resolution, context build,
 * generator call, persistence — the request is flagged
 * `needs_manual_review = true` AND a draft reply with the fallback text is
 * still stored. This guarantees that the dashboard always has something
 * to show next to a request, and a human is paged via the manual-review
 * flag.
 *
 * Dependencies are resolved inside `handle()` (rather than via parameter
 * injection on the job's `handle` signature) so a missing OPENAI_API_KEY
 * is caught by this job's try-block and turned into a manual-review row,
 * instead of bubbling out of the worker as an uncaught exception during
 * sync-queue execution.
 */
final class GenerateReservationReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $reservationRequestId) {}

    public function handle(): void
    {
        /** @var LoggerInterface $logger */
        $logger = app(LoggerInterface::class);

        $request = ReservationRequest::withoutGlobalScopes()->find($this->reservationRequestId);
        if ($request === null) {
            return;
        }

        $context = null;
        try {
            $builder = app(ReservationContextBuilder::class);
            $generator = app(ReplyGenerator::class);

            $context = $builder->build($request);
            $body = $generator->generate($context);

            ReservationReply::create([
                'reservation_request_id' => $request->id,
                'status' => ReservationReplyStatus::Draft,
                'body' => $body,
                'ai_prompt_snapshot' => $context,
            ]);
        } catch (Throwable $e) {
            // Log without leaking the context payload — only the error
            // message and the request id end up in the log line.
            $logger->warning('reservation.reply.generate_failed', [
                'reservation_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            $request->forceFill(['needs_manual_review' => true])->save();

            ReservationReply::create([
                'reservation_request_id' => $request->id,
                'status' => ReservationReplyStatus::Draft,
                'body' => OpenAiReplyGenerator::FALLBACK_TEXT,
                'ai_prompt_snapshot' => $context,
            ]);
        }
    }
}
