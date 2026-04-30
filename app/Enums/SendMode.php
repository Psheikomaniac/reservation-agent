<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Restaurant-level trust mode for outbound replies (PRD-007).
 *
 * - Manual:  every reply requires operator approval (V1.0 default).
 * - Shadow:  AI drafts, the system would auto-send if Auto were on, but
 *            the actual send still requires approval; the system tracks
 *            how often the operator modified the draft (shadow stats).
 * - Auto:    eligible replies are scheduled for automatic send after
 *            a 60-second cancel window; ineligible replies fall back
 *            to Manual via the AutoSendDecider hard-gates.
 */
enum SendMode: string
{
    case Manual = 'manual';
    case Shadow = 'shadow';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuelle Freigabe',
            self::Shadow => 'Shadow-Modus (Test)',
            self::Auto => 'Automatischer Versand',
        };
    }
}
