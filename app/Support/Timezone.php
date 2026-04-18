<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

final class Timezone
{
    public static function localToUtc(string $input, string $timezone): Carbon
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Unknown timezone: {$timezone}", previous: $e);
        }

        try {
            $local = Carbon::createFromFormat('Y-m-d H:i', $input, $zone);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Invalid local date-time input: {$input}", previous: $e);
        }

        if ($local === false) {
            throw new InvalidArgumentException("Invalid local date-time input: {$input}");
        }

        return $local->setTimezone('UTC');
    }
}
