<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Response-time statistics over the analytics window (PRD-008).
 *
 * Both values are in **whole minutes** and represent the elapsed time
 * between a request being received and the operator-approved reply
 * being marked sent. Median is the operator-friendly central tendency;
 * p90 surfaces the long-tail "I should have answered that already"
 * cases the median hides.
 *
 * `null` on either field means the window had too few replies to
 * produce a meaningful statistic — the dashboard renders an "—" cell
 * in that state rather than a misleading zero.
 */
final readonly class ResponseTimeStats
{
    public function __construct(
        public ?int $medianMinutes,
        public ?int $p90Minutes,
        public int $sampleSize,
    ) {}

    public static function empty(): self
    {
        return new self(null, null, 0);
    }
}
