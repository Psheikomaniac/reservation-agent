<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReservationReplyStatus;
use App\Events\OpenAiAuthenticationFailed;
use App\Exceptions\AI\OpenAiAuthenticationException;
use App\Exceptions\AI\OpenAiRateLimitException;
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
class GenerateReservationReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    /** Delay before re-attempting after a 429 (PRD-005). */
    public const int RATE_LIMIT_RETRY_DELAY_SECONDS = 60;

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
        } catch (OpenAiRateLimitException $e) {
            // First 429 → release back to queue with the configured delay.
            // Second 429 falls through to the generic-fallback path.
            if ($this->attempts() < $this->tries) {
                $this->release(self::RATE_LIMIT_RETRY_DELAY_SECONDS);

                return;
            }

            $this->storeFallbackDraft($request, $context, $logger, '429 rate-limit after retry');
        } catch (OpenAiAuthenticationException $e) {
            // 401 → admin notification, no retry. Always fall straight
            // through to the fallback path so the dashboard still has
            // something to show.
            OpenAiAuthenticationFailed::dispatch();

            $this->storeFallbackDraft($request, $context, $logger, '401 authentication failed');
        } catch (Throwable $e) {
            // 5xx, timeouts, network, builder/persistence errors,
            // missing API key at DI time → immediate fallback. No retry.
            $this->storeFallbackDraft($request, $context, $logger, $e->getMessage());
        }
    }

    /**
     * Persist the fallback draft and flag the request for manual review.
     * Always called from inside a handled-exception branch — never from
     * the happy path.
     *
     * @param  array<string, mixed>|null  $context
     */
    private function storeFallbackDraft(
        ReservationRequest $request,
        ?array $context,
        LoggerInterface $logger,
        string $reason,
    ): void {
        $logger->warning('reservation.reply.generate_failed', [
            'reservation_request_id' => $request->id,
            'reason' => $reason,
        ]);

        $request->forceFill(['needs_manual_review' => true])->save();

        ReservationReply::create([
            'reservation_request_id' => $request->id,
            'status' => ReservationReplyStatus::Draft,
            'body' => OpenAiReplyGenerator::FALLBACK_TEXT,
            'ai_prompt_snapshot' => $context,
            'is_fallback' => true,
        ]);
    }
}
