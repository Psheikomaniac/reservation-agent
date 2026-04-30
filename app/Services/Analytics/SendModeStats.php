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
 */
final readonly class SendModeStats
{
    public function __construct(
        public int $manual,
        public int $shadow,
        public int $auto,
        public int $shadowComparedSampleSize,
        public ?float $takeoverRate,
    ) {}
}
