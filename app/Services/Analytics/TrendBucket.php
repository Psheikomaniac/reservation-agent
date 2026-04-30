<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * One point on the trend chart (PRD-008).
 *
 * The aggregator gap-fills missing buckets with `count = 0` so the
 * chart never has holes. `label` is a human-readable bucket key
 * (e.g. `"14:00"` for the today/hour view, `"2026-04-29"` for the
 * 7d/30d/day view); `bucketStart` is the Carbon-friendly ISO-8601
 * timestamp Vue plots against the x-axis.
 */
final readonly class TrendBucket
{
    public function __construct(
        public string $label,
        public string $bucketStart,
        public int $count,
    ) {}
}
