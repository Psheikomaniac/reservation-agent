<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsRange;
use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side aggregator for the PRD-008 dashboard.
 *
 * Returns a fully-populated {@see AnalyticsSnapshot} for a single
 * restaurant + range pair. Aggregation runs against the OLTP
 * `reservation_requests` table (no separate datalake) and the result
 * is cached for 5 minutes per `(restaurant_id, range)` so the
 * dashboard can poll cheaply.
 *
 * Multi-tenant isolation is explicit: the aggregator receives the
 * `Restaurant` from the controller, drops the auth-driven
 * `RestaurantScope`, and rebuilds every query with a literal
 * `where('restaurant_id', $restaurant->id)`. That avoids both the
 * "auth user is null in jobs" trap and the "wrong tenant cached"
 * trap a global scope would invite.
 *
 * Issue #226 wires the totals / by-source / by-status branch;
 * issue #227 adds `responseTime`. Sibling issues still fill
 * `editRate`, `sendModeStats` and `trends`.
 */
final readonly class AnalyticsAggregator
{
    private const int CACHE_TTL_MINUTES = 5;

    public function __construct(
        private CacheRepository $cache,
    ) {}

    public function aggregate(Restaurant $restaurant, AnalyticsRange $range): AnalyticsSnapshot
    {
        $cacheKey = sprintf('analytics:%d:%s', $restaurant->id, $range->value);

        return $this->cache->remember(
            $cacheKey,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn (): AnalyticsSnapshot => new AnalyticsSnapshot(
                range: $range,
                totals: $this->totals($restaurant, $range),
                sources: $this->bySource($restaurant, $range),
                statusBreakdown: $this->byStatus($restaurant, $range),
                responseTime: $this->responseTime($restaurant, $range),
                editRate: null,
                sendModeStats: null,
                trends: [],
            ),
        );
    }

    /**
     * @return array<string, int>
     */
    private function totals(Restaurant $restaurant, AnalyticsRange $range): array
    {
        return [
            'total' => $this->baseQuery($restaurant, $range)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function bySource(Restaurant $restaurant, AnalyticsRange $range): array
    {
        $counts = $this->baseQuery($restaurant, $range)
            ->selectRaw('source, COUNT(*) as aggregate')
            ->groupBy('source')
            ->pluck('aggregate', 'source')
            ->all();

        $result = [];
        foreach (ReservationSource::cases() as $source) {
            $result[$source->value] = (int) ($counts[$source->value] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function byStatus(Restaurant $restaurant, AnalyticsRange $range): array
    {
        $counts = $this->baseQuery($restaurant, $range)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $result = [];
        foreach (ReservationStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * Median + p90 minutes between `request->created_at` and the
     * **first** sent reply's `sent_at`.
     *
     * Only the `Sent` reply state counts: `Draft`, `Approved`,
     * `Failed`, `Shadow`, `ScheduledAutoSend` and `CancelledAuto`
     * are deliberately ignored — `Approved` exists in the schema
     * but its `sent_at` is `null` until the SMTP send actually
     * completes, and the shadow / auto-send branches are a
     * different time-to-customer story (PRD-008 § Response-Time).
     *
     * Percentiles are computed in PHP because SQLite has no
     * `PERCENTILE_CONT`. Up to ~10k rows per range that's well
     * inside the 500 ms budget the performance benchmark issue
     * defends; above that we'd swap this for an aggregation
     * repository with DB-specific implementations.
     */
    private function responseTime(Restaurant $restaurant, AnalyticsRange $range): ResponseTimeStats
    {
        $timezone = $restaurant->timezone ?? config('app.timezone');

        $replies = ReservationReply::query()
            ->withoutGlobalScopes()
            ->where('status', ReservationReplyStatus::Sent->value)
            ->whereNotNull('sent_at')
            ->whereHas(
                'reservationRequest',
                fn (Builder $query) => $query
                    ->withoutGlobalScopes()
                    ->where('restaurant_id', $restaurant->id)
                    ->where('created_at', '>=', $range->startsAt($timezone))
                    ->where('created_at', '<=', $range->endsAt($timezone)),
            )
            ->with([
                'reservationRequest' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->orderBy('sent_at')
            ->get();

        $deltas = $replies
            ->groupBy('reservation_request_id')
            ->map(fn ($group) => $group->first())
            ->map(static fn (ReservationReply $reply): int => (int) max(
                0,
                $reply->reservationRequest->created_at->diffInMinutes($reply->sent_at),
            ))
            ->sort()
            ->values()
            ->all();

        if ($deltas === []) {
            return ResponseTimeStats::empty();
        }

        return new ResponseTimeStats(
            medianMinutes: $this->percentile($deltas, 0.5),
            p90Minutes: $this->percentile($deltas, 0.9),
            sampleSize: count($deltas),
        );
    }

    /**
     * Linear-interpolated percentile over a pre-sorted ascending
     * array of integers. Returns `null` for an empty list.
     *
     * Algorithm matches NumPy's `linear` interpolation:
     *   rank = p * (n - 1)
     *   value = a[floor(rank)] + (rank - floor(rank))
     *           * (a[ceil(rank)] - a[floor(rank)])
     *
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $p): ?int
    {
        $n = count($sorted);

        if ($n === 0) {
            return null;
        }

        if ($n === 1) {
            return $sorted[0];
        }

        $rank = $p * ($n - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        $fraction = $rank - $lower;

        return (int) round(
            $sorted[$lower] + $fraction * ($sorted[$upper] - $sorted[$lower]),
        );
    }

    /**
     * Tenant-scoped, range-bounded base query. Drops the global
     * `RestaurantScope` (which depends on Auth) and rebuilds the
     * tenant predicate explicitly so the aggregator works in jobs,
     * console commands and tests.
     *
     * @return Builder<ReservationRequest>
     */
    private function baseQuery(Restaurant $restaurant, AnalyticsRange $range): Builder
    {
        $timezone = $restaurant->timezone ?? config('app.timezone');

        return ReservationRequest::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)
            ->where('created_at', '>=', $range->startsAt($timezone))
            ->where('created_at', '<=', $range->endsAt($timezone));
    }
}
