<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsRange;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
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
 * Issue #226 wires the totals / by-source / by-status branch.
 * Sibling issues fill `responseTime`, `editRate`, `sendModeStats`
 * and `trends`.
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
                responseTime: ResponseTimeStats::empty(),
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
