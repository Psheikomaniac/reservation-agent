<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\SendMode;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Single authoritative source of "do we auto-send this reply?" (PRD-007).
 *
 * Skeleton in this issue: load the reply's restaurant and mode, run the
 * (currently stub) hard-gate check, and dispatch to a manual / shadow /
 * auto-send decision. The hard-gate cascade lands in #215 — when it
 * trips, the decider falls back to manual regardless of the restaurant's
 * configured mode, so the operator's safety net is never bypassed.
 *
 * `final` per the project default for services. The cascade is tested
 * end-to-end through real factory data (the gates in #215 will follow
 * the same pattern), so no test-only subclass seam is needed.
 */
final class AutoSendDecider
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function decide(ReservationReply $reply): AutoSendDecision
    {
        $request = $reply->reservationRequest;
        $restaurant = $request?->restaurant;

        if ($request === null || $restaurant === null) {
            // Without a parent request + restaurant we have no policy to
            // apply; the safest answer is the most conservative one.
            $this->logger->warning('auto-send decider: missing parent context, falling back to manual', [
                'reservation_reply_id' => $reply->id,
            ]);

            return AutoSendDecision::manual('missing_parent_context');
        }

        if ($gate = $this->blockedByHardGate($reply, $request, $restaurant)) {
            return AutoSendDecision::manual($gate);
        }

        $mode = $restaurant->send_mode;
        if (! $mode instanceof SendMode) {
            throw new RuntimeException('Restaurant send_mode must be a SendMode enum (Eloquent cast).');
        }

        return match ($mode) {
            SendMode::Manual => AutoSendDecision::manual('mode_manual'),
            SendMode::Shadow => AutoSendDecision::shadow('mode_shadow'),
            SendMode::Auto => AutoSendDecision::autoSend('mode_auto'),
        };
    }

    /**
     * Stub. The six concrete hard gates from PRD-007 land in #215.
     * Returning a non-null reason short-circuits the cascade to manual.
     *
     * Signature kept compatible with the future implementation:
     * receives the reply plus its already-loaded parent context so the
     * gates don't have to re-query.
     */
    private function blockedByHardGate(
        ReservationReply $reply,
        ReservationRequest $request,
        Restaurant $restaurant,
    ): ?string {
        return null;
    }
}
