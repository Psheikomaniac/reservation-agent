<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Renders a secret for the UI as four mask glyphs plus its last four
 * characters, e.g. "••••3456". Never returns the secret itself. Secrets of
 * four characters or fewer are fully masked so nothing leaks.
 */
final class SecretMask
{
    public static function tail4(?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        if (Str::length($secret) <= 4) {
            return '••••';
        }

        return '••••'.Str::substr($secret, -4);
    }
}
