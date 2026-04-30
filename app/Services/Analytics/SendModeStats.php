<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Send-mode usage breakdown over the analytics window (PRD-008).
 *
 * Counts of replies created in each PRD-007 mode plus the live shadow
 * takeover-rate (fraction of shadow replies the operator accepted
 * verbatim). `takeoverRate` is `null` when no shadow replies were
 * compared in the window — the dashboard renders an "—" cell rather
 * than a misleading zero.
 *
 * `topHardGateReasons` lists the most frequent reasons the auto-send
 * pipeline fell back to manual (`decision = manual` audit rows that
 * are NOT `mode_manual`), capped at three entries. Empty list when
 * the restaurant is not in `auto` mode or no blockades occurred.
 */
final readonly class SendModeStats
{
    /**
     * @param  list<array{reason: string, count: int}>  $topHardGateReasons
     */
    public function __construct(
        public int $manual,
        public int $shadow,
        public int $auto,
        public int $shadowComparedSampleSize,
        public ?float $takeoverRate,
        public array $topHardGateReasons = [],
    ) {}
}
