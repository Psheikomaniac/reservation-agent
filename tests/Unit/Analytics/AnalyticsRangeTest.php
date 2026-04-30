<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Enums\AnalyticsRange;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class AnalyticsRangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-04-30 10:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_starts_at_midnight_in_the_restaurant_timezone(): void
    {
        $start = AnalyticsRange::Today->startsAt('Europe/Berlin');

        // 2026-04-30 10:30 UTC is 2026-04-30 12:30 Berlin (DST: +02:00),
        // so "today" in Berlin starts at 2026-04-30 00:00 Berlin
        // which is 2026-04-29 22:00 UTC.
        $this->assertSame('2026-04-29T22:00:00+00:00', $start->utc()->toIso8601String());
    }

    public function test_last_7_days_starts_six_days_before_today_at_midnight(): void
    {
        $start = AnalyticsRange::Last7Days->startsAt('UTC');

        // 7 days INCLUDING today → start = today - 6 days at 00:00.
        $this->assertSame('2026-04-24T00:00:00+00:00', $start->toIso8601String());
    }

    public function test_last_30_days_starts_29_days_before_today_at_midnight(): void
    {
        $start = AnalyticsRange::Last30Days->startsAt('UTC');

        $this->assertSame('2026-04-01T00:00:00+00:00', $start->toIso8601String());
    }

    public function test_ends_at_returns_now_in_the_given_timezone(): void
    {
        $end = AnalyticsRange::Today->endsAt('UTC');

        $this->assertSame('2026-04-30T10:30:00+00:00', $end->toIso8601String());
    }

    public function test_today_uses_hour_buckets(): void
    {
        $this->assertSame('hour', AnalyticsRange::Today->bucketSize());
        $this->assertSame(24, AnalyticsRange::Today->bucketCount());
    }

    public function test_seven_and_thirty_day_ranges_use_day_buckets(): void
    {
        $this->assertSame('day', AnalyticsRange::Last7Days->bucketSize());
        $this->assertSame(7, AnalyticsRange::Last7Days->bucketCount());

        $this->assertSame('day', AnalyticsRange::Last30Days->bucketSize());
        $this->assertSame(30, AnalyticsRange::Last30Days->bucketCount());
    }

    public function test_string_value_is_the_form_request_friendly_token(): void
    {
        // The form-request validator pulls these from `Rule::enum`.
        $this->assertSame('today', AnalyticsRange::Today->value);
        $this->assertSame('7d', AnalyticsRange::Last7Days->value);
        $this->assertSame('30d', AnalyticsRange::Last30Days->value);
    }
}
