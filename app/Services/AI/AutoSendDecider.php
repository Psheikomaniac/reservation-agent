<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\SendMode;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Single authoritative source of "do we auto-send this reply?" (PRD-007).
 *
 * The hard-gate cascade lands here: when a gate trips, the decider falls
 * back to manual regardless of the restaurant's configured mode, so the
 * operator's safety net is never bypassed. Gate order is significant —
 * the first matching gate wins and short-circuits later checks.
 *
 * `final` per the project default for services. The cascade is tested
 * end-to-end through real factory data.
 */
final class AutoSendDecider
{
    public const string REASON_NEEDS_MANUAL_REVIEW = 'needs_manual_review';

    public const string REASON_FALLBACK_TEXT = 'fallback_text';

    public const string REASON_PARTY_SIZE_OVER_LIMIT = 'party_size_over_limit';

    public const string REASON_SHORT_NOTICE = 'short_notice';

    public const string REASON_FIRST_TIME_GUEST = 'first_time_guest';

    public const string REASON_LOW_CONFIDENCE_EMAIL = 'low_confidence_email';

    /**
     * Email-source confidence threshold below which the decider falls
     * back to manual. Mirrors the PRD-003/PRD-007 contract.
     */
    private const float EMAIL_CONFIDENCE_THRESHOLD = 0.9;

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
     * Run the six hard gates in cascade order. The first matching gate
     * wins; no plugin architecture, V2.0 keeps the order hard-coded so
     * the audit trail is deterministic.
     *
     * The reply, request and restaurant are passed in already-loaded so
     * gates avoid re-querying parents.
     */
    private function blockedByHardGate(
        ReservationReply $reply,
        ReservationRequest $request,
        Restaurant $restaurant,
    ): ?string {
        if ($request->needs_manual_review) {
            return self::REASON_NEEDS_MANUAL_REVIEW;
        }

        if ($this->isFallbackText($reply)) {
            return self::REASON_FALLBACK_TEXT;
        }

        if ($request->party_size > $restaurant->auto_send_party_size_max) {
            return self::REASON_PARTY_SIZE_OVER_LIMIT;
        }

        if ($this->isShortNotice($request, $restaurant)) {
            return self::REASON_SHORT_NOTICE;
        }

        if ($this->isFirstTimeGuest($request)) {
            return self::REASON_FIRST_TIME_GUEST;
        }

        if ($this->isLowConfidenceEmail($request)) {
            return self::REASON_LOW_CONFIDENCE_EMAIL;
        }

        return null;
    }

    /**
     * Match against the PRD-005 fallback constant. PRD-007 risk note
     * acknowledges the brittleness of string matching; #216 introduces
     * a dedicated `is_fallback` flag that will replace this check.
     */
    private function isFallbackText(ReservationReply $reply): bool
    {
        return $reply->body === OpenAiReplyGenerator::FALLBACK_TEXT;
    }

    /**
     * True when the requested time is closer than the restaurant's
     * configured minimum lead time (also true if `desired_at` already
     * lies in the past — those need an operator regardless).
     */
    private function isShortNotice(ReservationRequest $request, Restaurant $restaurant): bool
    {
        if ($request->desired_at === null) {
            return false;
        }

        $minutesUntilDesired = now()->diffInMinutes($request->desired_at, false);

        return $minutesUntilDesired < $restaurant->auto_send_min_lead_time_minutes;
    }

    /**
     * Trust requires a prior **confirmed** reservation under the same
     * guest email at the same restaurant. Declined / replied / cancelled
     * histories don't qualify — those signal trouble or attrition, not
     * a returning guest. Anonymous requests (no email) are treated as
     * first-time on purpose.
     */
    private function isFirstTimeGuest(ReservationRequest $request): bool
    {
        if ($request->guest_email === null || $request->guest_email === '') {
            return true;
        }

        return ReservationRequest::query()
            ->where('restaurant_id', $request->restaurant_id)
            ->where('guest_email', $request->guest_email)
            ->where('id', '!=', $request->id)
            ->where('status', ReservationStatus::Confirmed)
            ->doesntExist();
    }

    /**
     * Email-sourced requests carry a parser confidence in `raw_payload`
     * (PRD-003). Below 0.9 the parse is too uncertain to trust for an
     * automated send. Web-form requests bypass this gate entirely —
     * structured input has no parsing step.
     */
    private function isLowConfidenceEmail(ReservationRequest $request): bool
    {
        if ($request->source !== ReservationSource::Email) {
            return false;
        }

        $payload = $request->raw_payload;
        $confidence = is_array($payload) ? ($payload['confidence'] ?? 0) : 0;

        return (float) $confidence < self::EMAIL_CONFIDENCE_THRESHOLD;
    }
}
