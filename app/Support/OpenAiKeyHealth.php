<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Single-flag persistence for the "OpenAI key rejected" admin signal
 * (PRD-005 / issue #76).
 *
 * Cache-backed, app-wide (PRD-005 V1.0 uses one global API key). When
 * the next successful OpenAI call lands, the flag is cleared so the
 * admin notification disappears without requiring operator action.
 *
 * Dismissal by the operator is intentionally NOT implemented for V1.0:
 * a transient "I dismissed this and now don't realise the key is still
 * broken" failure mode is worse than waiting for the next successful
 * call to clear it.
 */
final class OpenAiKeyHealth
{
    private const string CACHE_KEY = 'reservations.openai.key_rejected_at';

    private const int RETENTION_SECONDS = 7 * 24 * 60 * 60;

    public static function flagAsRejected(): void
    {
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), self::RETENTION_SECONDS);
    }

    public static function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function rejectedAt(): ?string
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_string($value) ? $value : null;
    }
}
