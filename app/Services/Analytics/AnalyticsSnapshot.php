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
 * - `trends`: ordered list of buckets, gap-filled per `range->bucketCount()`.
 */
final readonly class AnalyticsSnapshot
{
    /**
     * @param  array<string, int>  $totals
     * @param  array<string, int>  $sources
     * @param  array<string, int>  $statusBreakdown
     * @param  list<TrendBucket>  $trends
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
    ) {}
}
