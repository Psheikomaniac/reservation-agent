<?php

declare(strict_types=1);

namespace App\Services\AutoSend;

use App\Enums\SlotState;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Availability\SlotAvailability;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a web reservation may be confirmed synchronously in the
 * submit path (PRD-014), or must fall back to the normal owner-approval flow.
 *
 * The gate cascade is hard-coded and order-significant — the first tripped gate
 * wins. Unlike the PRD-007 auto-send path, `first_time_guest` and
 * `needs_manual_review` are deliberately NOT gates here: web-form input is
 * structured (no parse risk) and a first-time filter would punish the very
 * online first-timers the channel exists to win (see PRD-014 risks). The
 * PRD-007 restaurant limits (`auto_send_party_size_max`,
 * `auto_send_min_lead_time_minutes`) are reused.
 *
 * `final`, stateless: availability is delegated to {@see SlotAvailability}.
 */
final class WebSyncConfirmDecider
{
    public const string REASON_GLOBAL_KILL = 'global_kill';

    public const string REASON_DISABLED = 'web_sync_confirm_disabled';

    public const string REASON_SLOT_NOT_FREE = 'slot_not_deterministic_free';

    public const string REASON_PARTY_SIZE_OVER_LIMIT = 'party_size_over_limit';

    public const string REASON_SHORT_NOTICE = 'short_notice';

    public function __construct(
        private readonly SlotAvailability $availability,
        private readonly LoggerInterface $logger,
    ) {}

    public function decide(ReservationRequest $request): WebSyncConfirmDecision
    {
        // 1. Global incident killswitch — stops every sync-confirm at once.
        if ((bool) config('reservations.web_sync_confirm.kill', false)) {
            return WebSyncConfirmDecision::skip(self::REASON_GLOBAL_KILL);
        }

        // 2. Per-restaurant opt-in.
        $restaurant = $request->restaurant;
        if ($restaurant === null) {
            $this->logger->warning('web sync confirm: reservation has no restaurant, skipping', [
                'reservation_request_id' => $request->id,
            ]);

            return WebSyncConfirmDecision::skip(self::REASON_DISABLED);
        }

        if (! $restaurant->web_sync_confirm_enabled) {
            return WebSyncConfirmDecision::skip(self::REASON_DISABLED);
        }

        // 3. Slot must be deterministically free (a missing time can never be).
        if ($request->desired_at === null) {
            return WebSyncConfirmDecision::skip(self::REASON_SLOT_NOT_FREE);
        }

        $slot = $this->availability->forSlot(
            $request->restaurant_id,
            CarbonImmutable::instance($request->desired_at),
            $request->party_size,
        );

        if ($slot->state !== SlotState::Free) {
            return WebSyncConfirmDecision::skip(self::REASON_SLOT_NOT_FREE);
        }

        // 4. Reused PRD-007 hard limits.
        if ($request->party_size > $restaurant->auto_send_party_size_max) {
            return WebSyncConfirmDecision::skip(self::REASON_PARTY_SIZE_OVER_LIMIT);
        }

        if ($this->isShortNotice($request, $restaurant)) {
            return WebSyncConfirmDecision::skip(self::REASON_SHORT_NOTICE);
        }

        return WebSyncConfirmDecision::proceed($slot);
    }

    private function isShortNotice(ReservationRequest $request, Restaurant $restaurant): bool
    {
        $minutesUntilDesired = now()->diffInMinutes($request->desired_at, false);

        return $minutesUntilDesired < $restaurant->auto_send_min_lead_time_minutes;
    }
}
