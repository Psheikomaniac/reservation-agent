<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * PRD-010 § Email-Digest. Snapshot of "what happened today" for a
 * single restaurant, derived from the user's restaurant context. The
 * DTO is final + readonly so the mailable can carry it across the
 * queue boundary without surprise mutations.
 *
 * The denominator for "today" is the **restaurant's local timezone**,
 * not the server timezone — owners in CET expect the 18:00 digest to
 * cover everything that arrived since midnight CET, even if the
 * scheduler ticks in UTC.
 */
final readonly class DigestSummary
{
    public function __construct(
        public string $restaurantName,
        public int $totalToday,
        public int $confirmed,
        public int $pending,
        public int $needsReview,
        public string $dashboardUrl,
    ) {}

    /**
     * Build the summary for the given user. Counts are scoped by
     * `restaurant_id` so a multi-restaurant chain only ever sees its
     * own numbers (PRD-010 multi-tenant isolation requirement).
     *
     * The caller must ensure `$user->restaurant` is loaded; users
     * without a restaurant are filtered out earlier in the digest job.
     */
    public static function forUser(User $user, string $dashboardUrl): self
    {
        $restaurant = $user->restaurant;
        if ($restaurant === null) {
            // Defensive guard; the digest job already skips these,
            // but a direct caller would otherwise crash on a missing
            // timezone string.
            throw new \LogicException('DigestSummary requires a user with a restaurant.');
        }

        $today = Carbon::now($restaurant->timezone)->startOfDay();
        $tomorrow = $today->copy()->addDay();

        $base = ReservationRequest::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereBetween('created_at', [
                $today->copy()->utc(),
                $tomorrow->copy()->utc(),
            ]);

        $totalToday = (clone $base)->count();
        $confirmed = (clone $base)->where('status', ReservationStatus::Confirmed)->count();
        $pending = (clone $base)
            ->whereIn('status', [ReservationStatus::New, ReservationStatus::InReview])
            ->count();
        $needsReview = (clone $base)->where('needs_manual_review', true)->count();

        return new self(
            restaurantName: $restaurant->name,
            totalToday: $totalToday,
            confirmed: $confirmed,
            pending: $pending,
            needsReview: $needsReview,
            dashboardUrl: $dashboardUrl,
        );
    }
}
