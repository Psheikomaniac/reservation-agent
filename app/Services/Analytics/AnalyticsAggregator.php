<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsRange;
use App\Enums\MessageDirection;
use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\SendMode;
use App\Models\AutoSendAudit;
use App\Models\ReservationMessage;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * issue #227 adds `responseTime`; issue #228 adds `editRate` and
 * `sendModeStats`; issue #229 adds the three trend series.
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
                editRate: $this->editRate($restaurant, $range),
                sendModeStats: $this->sendModeStats($restaurant, $range),
                trends: $this->requestsTrend($restaurant, $range),
                confirmationRateTrend: $this->confirmationRateTrend($restaurant, $range),
                threadRepliesTrend: $this->threadRepliesTrend($restaurant, $range),
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

        // Fetch only the two timestamps we need for percentile maths
        // — the previous Eloquent path materialised every reply
        // model + eager-loaded request. At 10 k rows that blew the
        // PHP heap; this raw join keeps the working set to two
        // strings per reply.
        $rows = DB::table('reservation_replies')
            ->join('reservation_requests', 'reservation_replies.reservation_request_id', '=', 'reservation_requests.id')
            ->where('reservation_replies.status', ReservationReplyStatus::Sent->value)
            ->whereNotNull('reservation_replies.sent_at')
            ->where('reservation_requests.restaurant_id', $restaurant->id)
            ->where('reservation_requests.created_at', '>=', $range->startsAt($timezone))
            ->where('reservation_requests.created_at', '<=', $range->endsAt($timezone))
            ->orderBy('reservation_replies.sent_at')
            ->get([
                'reservation_replies.reservation_request_id as request_id',
                'reservation_replies.sent_at as reply_sent_at',
                'reservation_requests.created_at as request_created_at',
            ]);

        // First sent reply per request (the rows are pre-sorted by
        // sent_at so the earliest wins on first-write).
        $earliestPerRequest = [];
        foreach ($rows as $row) {
            if (! isset($earliestPerRequest[$row->request_id])) {
                $earliestPerRequest[$row->request_id] = $row;
            }
        }

        if ($earliestPerRequest === []) {
            return ResponseTimeStats::empty();
        }

        $deltas = [];
        foreach ($earliestPerRequest as $row) {
            $deltas[] = (int) max(
                0,
                Carbon::parse($row->request_created_at)
                    ->diffInMinutes(Carbon::parse($row->reply_sent_at)),
            );
        }

        sort($deltas);

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
     * Edit-rate: fraction of `Sent` / `Approved` replies whose body
     * was modified vs the AI's original draft snapshotted in
     * `ai_prompt_snapshot.original_body`.
     *
     * Uses `json_extract` (SQLite + MySQL 8 compatible). Replies
     * created before the original-body snapshot was introduced have
     * no `original_body` key and are treated as **not modified**
     * (PRD-008 risk note: pre-extension data should not skew the
     * metric — they count toward the denominator but never as
     * edits). Postgres would need an alternative `->>` expression;
     * we'll wrap that behind a repository interface if Postgres
     * becomes the prod target.
     *
     * `null` when no comparable reply exists in the window — the
     * dashboard renders an "—" cell rather than a misleading 0 %.
     */
    private function editRate(Restaurant $restaurant, AnalyticsRange $range): ?float
    {
        $base = $this->repliesInRange($restaurant, $range)
            ->whereIn('reservation_replies.status', [
                ReservationReplyStatus::Sent->value,
                ReservationReplyStatus::Approved->value,
            ]);

        $total = (clone $base)->count();

        if ($total === 0) {
            return null;
        }

        $modified = (clone $base)
            ->whereColumn(
                'reservation_replies.body',
                '!=',
                DB::raw("json_extract(reservation_replies.ai_prompt_snapshot, '$.original_body')"),
            )
            ->count();

        return $modified / $total;
    }

    /**
     * PRD-007 send-mode breakdown. Returns `null` when the
     * restaurant is still on the V1.0 manual default — the
     * dashboard hides the section entirely in that case rather
     * than showing zeros that would suggest auto-send is wired up.
     *
     * `manual` / `shadow` counts come from `reservation_replies`
     * (filtered by `send_mode_at_creation`); `auto` and the top
     * hard-gate reasons come from `auto_send_audits`, which has
     * its own `restaurant_id` column from PRD-007 — no join needed.
     *
     * `topHardGateReasons` is the top 3 `decision = manual`
     * audit rows whose reason is NOT `mode_manual` (those are the
     * baseline "we are configured manual" entries, not blockades).
     */
    private function sendModeStats(Restaurant $restaurant, AnalyticsRange $range): ?SendModeStats
    {
        // `null` happens when a freshly-created Restaurant model was
        // never reloaded from the DB after insert — treated as the
        // V1.0 manual default (the column default is `manual`).
        if ($restaurant->send_mode === null || $restaurant->send_mode === SendMode::Manual) {
            return null;
        }

        $timezone = $restaurant->timezone ?? config('app.timezone');
        $start = $range->startsAt($timezone);
        $end = $range->endsAt($timezone);

        $manual = $this->repliesInRange($restaurant, $range)
            ->where('reservation_replies.send_mode_at_creation', SendMode::Manual->value)
            ->count();

        $shadow = $this->repliesInRange($restaurant, $range)
            ->where('reservation_replies.send_mode_at_creation', SendMode::Shadow->value)
            ->count();

        $shadowCompared = $this->repliesInRange($restaurant, $range)
            ->where('reservation_replies.send_mode_at_creation', SendMode::Shadow->value)
            ->whereNotNull('reservation_replies.shadow_compared_at')
            ->count();

        $shadowKept = $this->repliesInRange($restaurant, $range)
            ->where('reservation_replies.send_mode_at_creation', SendMode::Shadow->value)
            ->whereNotNull('reservation_replies.shadow_compared_at')
            ->where('reservation_replies.shadow_was_modified', false)
            ->count();

        $takeoverRate = $shadowCompared === 0 ? null : $shadowKept / $shadowCompared;

        $auto = AutoSendAudit::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('decision', AutoSendAudit::DECISION_AUTO_SEND)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        /** @var list<array{reason: string, count: int}> $topHardGateReasons */
        $topHardGateReasons = AutoSendAudit::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('decision', AutoSendAudit::DECISION_MANUAL)
            ->where('reason', '!=', 'mode_manual')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('reason, COUNT(*) as count')
            ->groupBy('reason')
            ->orderByDesc('count')
            ->limit(3)
            ->get()
            ->map(fn ($row) => [
                'reason' => (string) $row->reason,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        return new SendModeStats(
            manual: $manual,
            shadow: $shadow,
            auto: $auto,
            shadowComparedSampleSize: $shadowCompared,
            takeoverRate: $takeoverRate,
            topHardGateReasons: $topHardGateReasons,
        );
    }

    /**
     * Requests-per-bucket series, gap-filled. The chart shows total
     * activity over time; days/hours with zero requests render as
     * a baseline rather than a hole.
     *
     * @return list<TrendBucket>
     */
    private function requestsTrend(Restaurant $restaurant, AnalyticsRange $range): array
    {
        if ($range->bucketSize() === 'day') {
            $counts = $this->baseQuery($restaurant, $range)
                ->selectRaw('DATE(created_at) as bucket_key, COUNT(*) as count')
                ->groupBy('bucket_key')
                ->pluck('count', 'bucket_key')
                ->all();

            return $this->fillBuckets(
                $range,
                $restaurant,
                fn (string $key) => (int) ($counts[$key] ?? 0),
            );
        }

        // Hourly buckets are only used for the Today range — the
        // working set is at most one day's worth of rows, so the
        // PHP-side loop is cheap and stays portable across the
        // SQL dialects the project hasn't picked yet.
        $timezone = $restaurant->timezone ?? config('app.timezone');

        $counts = $this->baseQuery($restaurant, $range)
            ->pluck('created_at')
            ->reduce(function (array $carry, $createdAt) use ($range, $timezone): array {
                $key = $this->bucketKey($createdAt, $range, $timezone);
                $carry[$key] = ($carry[$key] ?? 0) + 1;

                return $carry;
            }, []);

        return $this->fillBuckets(
            $range,
            $restaurant,
            fn (string $key) => (int) ($counts[$key] ?? 0),
        );
    }

    /**
     * Confirmation-rate-per-bucket series. `count` carries the
     * integer percentage (0-100); buckets with zero requests
     * surface as 0 — the dashboard masks zero denominators to "—"
     * in the chart so a flat zero day isn't read as "everyone
     * declined".
     *
     * @return list<TrendBucket>
     */
    private function confirmationRateTrend(Restaurant $restaurant, AnalyticsRange $range): array
    {
        if ($range->bucketSize() === 'day') {
            $totals = $this->baseQuery($restaurant, $range)
                ->selectRaw('DATE(created_at) as bucket_key, COUNT(*) as count')
                ->groupBy('bucket_key')
                ->pluck('count', 'bucket_key')
                ->all();

            $confirmed = $this->baseQuery($restaurant, $range)
                ->where('status', ReservationStatus::Confirmed->value)
                ->selectRaw('DATE(created_at) as bucket_key, COUNT(*) as count')
                ->groupBy('bucket_key')
                ->pluck('count', 'bucket_key')
                ->all();

            return $this->fillBuckets(
                $range,
                $restaurant,
                function (string $key) use ($totals, $confirmed): int {
                    $total = (int) ($totals[$key] ?? 0);
                    if ($total === 0) {
                        return 0;
                    }

                    return (int) round(((int) ($confirmed[$key] ?? 0)) / $total * 100);
                },
            );
        }

        $timezone = $restaurant->timezone ?? config('app.timezone');

        $rows = $this->baseQuery($restaurant, $range)
            ->get(['created_at', 'status']);

        $totals = [];
        $confirmed = [];

        foreach ($rows as $row) {
            $key = $this->bucketKey($row->created_at, $range, $timezone);
            $totals[$key] = ($totals[$key] ?? 0) + 1;

            if ($row->status === ReservationStatus::Confirmed) {
                $confirmed[$key] = ($confirmed[$key] ?? 0) + 1;
            }
        }

        return $this->fillBuckets(
            $range,
            $restaurant,
            function (string $key) use ($totals, $confirmed): int {
                $total = (int) ($totals[$key] ?? 0);
                if ($total === 0) {
                    return 0;
                }

                return (int) round(((int) ($confirmed[$key] ?? 0)) / $total * 100);
            },
        );
    }

    /**
     * Thread-replies-per-bucket series. PRD-006: counts inbound
     * `reservation_messages` (`direction = in`) — guest replies that
     * reopen or extend the conversation. Outbound messages would
     * just measure how often we sent something and aren't a
     * meaningful engagement signal.
     *
     * @return list<TrendBucket>
     */
    private function threadRepliesTrend(Restaurant $restaurant, AnalyticsRange $range): array
    {
        $timezone = $restaurant->timezone ?? config('app.timezone');
        $start = $range->startsAt($timezone);
        $end = $range->endsAt($timezone);

        if ($range->bucketSize() === 'day') {
            $counts = DB::table('reservation_messages')
                ->join('reservation_requests', 'reservation_messages.reservation_request_id', '=', 'reservation_requests.id')
                ->where('reservation_messages.direction', MessageDirection::In->value)
                ->where('reservation_requests.restaurant_id', $restaurant->id)
                ->whereBetween('reservation_messages.created_at', [$start, $end])
                ->selectRaw('DATE(reservation_messages.created_at) as bucket_key, COUNT(*) as count')
                ->groupBy('bucket_key')
                ->pluck('count', 'bucket_key')
                ->all();

            return $this->fillBuckets(
                $range,
                $restaurant,
                fn (string $key) => (int) ($counts[$key] ?? 0),
            );
        }

        $createdAts = ReservationMessage::query()
            ->where('direction', MessageDirection::In->value)
            ->whereHas(
                'reservationRequest',
                fn (Builder $query) => $query
                    ->withoutGlobalScopes()
                    ->where('restaurant_id', $restaurant->id),
            )
            ->whereBetween('reservation_messages.created_at', [$start, $end])
            ->pluck('created_at');

        $counts = [];
        foreach ($createdAts as $createdAt) {
            $key = $this->bucketKey($createdAt, $range, $timezone);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $this->fillBuckets(
            $range,
            $restaurant,
            fn (string $key) => (int) ($counts[$key] ?? 0),
        );
    }

    /**
     * Map a row timestamp to its bucket key in the restaurant's
     * local timezone. PHP-side bucketing keeps the queries
     * database-agnostic — `strftime` (SQLite) and `DATE_FORMAT` /
     * `HOUR()` (MySQL) have different syntax and the eventual
     * Postgres choice would force a third dialect. Bucket
     * cardinality is bounded by `range->bucketCount()`
     * (≤ 30 days), so the PHP loop is cheap.
     */
    private function bucketKey(\DateTimeInterface $createdAt, AnalyticsRange $range, string $timezone): string
    {
        $localized = Carbon::instance($createdAt)->setTimezone($timezone);

        return match ($range->bucketSize()) {
            'hour' => $localized->format('H'),
            default => $localized->format('Y-m-d'),
        };
    }

    /**
     * Build a complete, ordered bucket list across the range and
     * fill each bucket's count from the resolver. Days/hours with
     * no rows still appear in the result so the line chart never
     * has holes (PRD-008 § Trend-Daten).
     *
     * @param  callable(string): int  $countResolver
     * @return list<TrendBucket>
     */
    private function fillBuckets(
        AnalyticsRange $range,
        Restaurant $restaurant,
        callable $countResolver,
    ): array {
        $timezone = $restaurant->timezone ?? config('app.timezone');
        $start = $range->startsAt($timezone);
        $buckets = [];

        if ($range->bucketSize() === 'hour') {
            for ($hour = 0; $hour < $range->bucketCount(); $hour++) {
                $cursor = $start->copy()->addHours($hour);
                $key = sprintf('%02d', $hour);
                $label = sprintf('%02d:00', $hour);
                $buckets[] = new TrendBucket(
                    label: $label,
                    bucketStart: $cursor->toIso8601String(),
                    count: $countResolver($key),
                );
            }

            return $buckets;
        }

        for ($day = 0; $day < $range->bucketCount(); $day++) {
            $cursor = $start->copy()->addDays($day);
            $key = $cursor->format('Y-m-d');
            $buckets[] = new TrendBucket(
                label: $key,
                bucketStart: $cursor->toIso8601String(),
                count: $countResolver($key),
            );
        }

        return $buckets;
    }

    /**
     * Reply-side base query joined to the request table for tenant
     * + range scoping. `reservation_replies` has no own
     * `restaurant_id` column (PRD-001 schema), so the predicate sits
     * on the joined `reservation_requests`. Both tables drop their
     * global `RestaurantScope` so the aggregator runs cleanly in
     * jobs, commands and tests without an authenticated user.
     *
     * Range filter is applied to `reservation_replies.created_at` —
     * a reply created in the window is what the analytics need to
     * count, regardless of when the original request was opened.
     *
     * @return Builder<ReservationReply>
     */
    private function repliesInRange(Restaurant $restaurant, AnalyticsRange $range): Builder
    {
        $timezone = $restaurant->timezone ?? config('app.timezone');

        return ReservationReply::query()
            ->withoutGlobalScopes()
            ->whereHas(
                'reservationRequest',
                fn (Builder $query) => $query
                    ->withoutGlobalScopes()
                    ->where('restaurant_id', $restaurant->id),
            )
            ->where('reservation_replies.created_at', '>=', $range->startsAt($timezone))
            ->where('reservation_replies.created_at', '<=', $range->endsAt($timezone));
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
