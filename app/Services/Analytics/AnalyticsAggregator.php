<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsRange;
use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\SendMode;
use App\Models\AutoSendAudit;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
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
 * `sendModeStats`. Sibling issues still fill `trends`.
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
