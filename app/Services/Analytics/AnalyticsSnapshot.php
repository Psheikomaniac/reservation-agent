<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsRange;

/**
 * Aggregator output for the PRD-008 dashboard. Plain readonly value
 * object — not an Eloquent model — so the controller can hand it to
 * `AnalyticsSnapshotResource` without ever touching the database
 * outside the cached aggregator pass.
 *
 * Field shapes:
 *
 * - `totals`: `['total' => int, 'new' => int, 'in_review' => int, ...]`
 * - `sources`: `['web_form' => int, 'email' => int]`
 * - `statusBreakdown`: `[ReservationStatus->value => int, ...]`
 * - `editRate`: 0.0–1.0, fraction of approved replies whose body
 *   differed from the snapshotted `original_body`. `null` when the
 *   window had no comparable replies.
 * - `sendModeStats`: PRD-007 breakdown, `null` when the restaurant is
 *   still on the V1.0 manual default and never recorded a non-manual
 *   mode in the window.
 * - `trends`: requests-per-bucket series, gap-filled per
 *   `range->bucketCount()`. `bucket.count` is the integer request
 *   count.
 * - `confirmationRateTrend`: confirmation-rate-per-bucket series,
 *   same gap-fill. `bucket.count` is the integer percentage (0-100);
 *   buckets with no requests return 0 (the dashboard masks zero
 *   denominators as "—" in the chart).
 * - `threadRepliesTrend`: thread-replies-per-bucket series (PRD-006
 *   inbound `reservation_messages`), same gap-fill.
 */
final readonly class AnalyticsSnapshot
{
    /**
     * @param  array<string, int>  $totals
     * @param  array<string, int>  $sources
     * @param  array<string, int>  $statusBreakdown
     * @param  list<TrendBucket>  $trends
     * @param  list<TrendBucket>  $confirmationRateTrend
     * @param  list<TrendBucket>  $threadRepliesTrend
     */
    public function __construct(
        public AnalyticsRange $range,
        public array $totals,
        public array $sources,
        public array $statusBreakdown,
        public ResponseTimeStats $responseTime,
        public ?float $editRate,
        public ?SendModeStats $sendModeStats,
        public array $trends,
        public array $confirmationRateTrend = [],
        public array $threadRepliesTrend = [],
    ) {}
}
