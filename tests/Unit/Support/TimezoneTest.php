<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Timezone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TimezoneTest extends TestCase
{
    public function test_local_to_utc_converts_berlin_summer_time(): void
    {
        $utc = Timezone::localToUtc('2026-07-15 19:00', 'Europe/Berlin');

        $this->assertSame('UTC', $utc->timezoneName);
        $this->assertSame('2026-07-15 17:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_local_to_utc_converts_berlin_winter_time(): void
    {
        $utc = Timezone::localToUtc('2026-01-15 19:00', 'Europe/Berlin');

        $this->assertSame('2026-01-15 18:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_local_to_utc_handles_dst_spring_forward_before_and_after(): void
    {
        // Europe/Berlin DST spring-forward: 2026-03-29 at 02:00 local jumps to 03:00 (skips 02:00–03:00).
        // 01:30 is still CET (+01:00) → 00:30 UTC.
        $before = Timezone::localToUtc('2026-03-29 01:30', 'Europe/Berlin');
        $this->assertSame('2026-03-29 00:30:00', $before->format('Y-m-d H:i:s'));

        // 03:30 is already CEST (+02:00) → 01:30 UTC.
        $after = Timezone::localToUtc('2026-03-29 03:30', 'Europe/Berlin');
        $this->assertSame('2026-03-29 01:30:00', $after->format('Y-m-d H:i:s'));

        // The gap (02:30 is skipped) must not produce 00:30 UTC — it rolls forward by the DST offset.
        $this->assertNotSame($before->format('Y-m-d H:i:s'), $after->format('Y-m-d H:i:s'));
    }

    public function test_local_to_utc_handles_dst_fall_back_consistently(): void
    {
        // Europe/Berlin DST fall-back: 2026-10-25 at 03:00 local returns to 02:00 (02:00–03:00 exists twice).
        // PHP resolves the ambiguous 02:30 to the second occurrence (CET, +01:00) → 01:30 UTC.
        $ambiguous = Timezone::localToUtc('2026-10-25 02:30', 'Europe/Berlin');
        $this->assertSame('2026-10-25 01:30:00', $ambiguous->format('Y-m-d H:i:s'));
    }

    public function test_invalid_input_format_raises_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Timezone::localToUtc('not-a-date', 'Europe/Berlin');
    }

    public function test_unknown_timezone_raises_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Timezone::localToUtc('2026-07-15 19:00', 'Europe/Atlantis');
    }
}
