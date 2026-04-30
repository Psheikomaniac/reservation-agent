<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * Time-window selector for the PRD-008 analytics dashboard.
 *
 * Three windows are supported in V2.0: today (intra-day, hour buckets),
 * the last 7 days, and the last 30 days. Larger ranges are deferred —
 * cache hit-rate and chart density both degrade past 30 days, and the
 * aggregator is not yet partitioned for that scale.
 *
 * Range bounds are computed in the restaurant's local timezone so a
 * "today" lookup at 23:30 Berlin doesn't slip into UTC tomorrow.
 */
enum AnalyticsRange: string
{
    case Today = 'today';
    case Last7Days = '7d';
    case Last30Days = '30d';

    /**
     * Inclusive lower bound of the range, anchored to the start of the
     * appropriate day in the restaurant timezone.
     */
    public function startsAt(string $timezone): Carbon
    {
        return match ($this) {
            self::Today => Carbon::now($timezone)->startOfDay(),
            self::Last7Days => Carbon::now($timezone)->startOfDay()->subDays(6),
            self::Last30Days => Carbon::now($timezone)->startOfDay()->subDays(29),
        };
    }

    /**
     * Inclusive upper bound of the range, anchored to "now". Today is
     * the only range that ends mid-day; the 7d and 30d windows still
     * include partial-today bucket so the most recent activity shows
     * up immediately.
     */
    public function endsAt(string $timezone): Carbon
    {
        return Carbon::now($timezone);
    }

    /**
     * Bucket granularity for the trend chart. `today` shows one bucket
     * per hour (24 buckets); `7d` and `30d` show one bucket per day.
     */
    public function bucketSize(): string
    {
        return match ($this) {
            self::Today => 'hour',
            self::Last7Days, self::Last30Days => 'day',
        };
    }

    /**
     * Number of buckets the trend chart should render. The aggregator
     * uses this to gap-fill empty buckets so the chart never has holes.
     */
    public function bucketCount(): int
    {
        return match ($this) {
            self::Today => 24,
            self::Last7Days => 7,
            self::Last30Days => 30,
        };
    }
}
