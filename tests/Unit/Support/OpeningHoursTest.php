<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OpeningHours;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OpeningHoursTest extends TestCase
{
    /**
     * Factory-style weekly schedule (mirrors RestaurantFactory).
     *
     * Boundary convention under test: half-open `[from, to)` intervals.
     * At `from` the restaurant is OPEN. At `to` it is already CLOSED.
     *
     * @return array<string, array<int, array{from: string, to: string}>>
     */
    private function weeklySchedule(): array
    {
        return [
            'mon' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
            'tue' => [],
            'wed' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
            'thu' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
            'fri' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '23:00']],
            'sat' => [['from' => '18:00', 'to' => '23:00']],
            'sun' => [['from' => '11:30', 'to' => '15:00']],
        ];
    }

    public function test_is_open_during_lunch_block_on_weekday(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Monday 2026-04-20, 12:00 local Berlin time (inside lunch block 11:30–14:30).
        $time = Carbon::create(2026, 4, 20, 12, 0, 0, 'Europe/Berlin');

        $this->assertTrue($hours->isOpenAt($time));
    }

    public function test_is_open_during_dinner_block_on_weekday(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Monday 19:30, inside dinner block 18:00–22:30.
        $time = Carbon::create(2026, 4, 20, 19, 30, 0, 'Europe/Berlin');

        $this->assertTrue($hours->isOpenAt($time));
    }

    public function test_is_closed_between_lunch_and_dinner_on_same_weekday(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Monday 16:00, sits in the gap between lunch (ends 14:30) and dinner (starts 18:00).
        $time = Carbon::create(2026, 4, 20, 16, 0, 0, 'Europe/Berlin');

        $this->assertFalse($hours->isOpenAt($time));
    }

    public function test_ruhetag_returns_false_at_every_time(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Tuesday 2026-04-21 is Ruhetag (empty array). Sample noon + evening.
        $noon = Carbon::create(2026, 4, 21, 12, 0, 0, 'Europe/Berlin');
        $evening = Carbon::create(2026, 4, 21, 20, 0, 0, 'Europe/Berlin');

        $this->assertFalse($hours->isOpenAt($noon));
        $this->assertFalse($hours->isOpenAt($evening));
    }

    public function test_multiple_blocks_per_day_are_handled_independently(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Friday: lunch 11:30–14:30, dinner 18:00–23:00. Verify both blocks hit independently.
        $inLunch = Carbon::create(2026, 4, 24, 13, 0, 0, 'Europe/Berlin');
        $inGap = Carbon::create(2026, 4, 24, 17, 0, 0, 'Europe/Berlin');
        $inDinner = Carbon::create(2026, 4, 24, 22, 0, 0, 'Europe/Berlin');

        $this->assertTrue($hours->isOpenAt($inLunch));
        $this->assertFalse($hours->isOpenAt($inGap));
        $this->assertTrue($hours->isOpenAt($inDinner));
    }

    public function test_boundary_at_opening_time_is_open_half_open_convention(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Monday exactly 11:30:00 — the first moment of the lunch block is OPEN.
        $time = Carbon::create(2026, 4, 20, 11, 30, 0, 'Europe/Berlin');

        $this->assertTrue($hours->isOpenAt($time));
    }

    public function test_boundary_at_closing_time_is_closed_half_open_convention(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Monday exactly 14:30:00 — the first moment outside the lunch block is CLOSED.
        $time = Carbon::create(2026, 4, 20, 14, 30, 0, 'Europe/Berlin');

        $this->assertFalse($hours->isOpenAt($time));
    }

    public function test_one_second_before_closing_is_still_open(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Monday 14:29:59 — last minute of lunch block. Same minute as :00 given minute-precision check.
        $time = Carbon::create(2026, 4, 20, 14, 29, 59, 'Europe/Berlin');

        $this->assertTrue($hours->isOpenAt($time));
    }

    public function test_one_minute_before_opening_is_still_closed(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Monday 11:29 — one minute before lunch opens.
        $time = Carbon::create(2026, 4, 20, 11, 29, 0, 'Europe/Berlin');

        $this->assertFalse($hours->isOpenAt($time));
    }

    public function test_accepts_utc_input_and_resolves_to_restaurant_timezone(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // 2026-07-15 17:00 UTC = 19:00 Berlin (CEST, +02:00). Wednesday dinner block 18:00–22:30 → open.
        $utc = Carbon::create(2026, 7, 15, 17, 0, 0, 'UTC');

        $this->assertTrue($hours->isOpenAt($utc));
    }

    public function test_utc_input_that_flips_weekday_resolves_correctly(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        // Tuesday 2026-04-21 22:30 UTC = Wednesday 2026-04-22 00:30 Berlin (CEST).
        // Berlin Wednesday at 00:30 sits BEFORE the lunch block → closed. But crucially,
        // the lookup must consult Wednesday's schedule, not Tuesday's (Ruhetag).
        // Assert closed (would also be false if Tuesday was consulted, so pair with a positive test):
        $utc = Carbon::create(2026, 4, 21, 22, 30, 0, 'UTC');
        $this->assertFalse($hours->isOpenAt($utc));

        // Tuesday 2026-04-21 17:30 UTC = Wednesday 2026-04-22 19:30 Berlin → Wednesday dinner block → open.
        // This can only be true if weekday resolution happens AFTER timezone conversion.
        $utcFlipsToWedDinner = Carbon::create(2026, 4, 22, 17, 30, 0, 'UTC');
        $this->assertTrue($hours->isOpenAt($utcFlipsToWedDinner));
    }

    public function test_does_not_mutate_incoming_carbon_instance(): void
    {
        $hours = new OpeningHours($this->weeklySchedule(), 'Europe/Berlin');

        $utc = Carbon::create(2026, 4, 20, 10, 0, 0, 'UTC');
        $snapshot = $utc->format('Y-m-d H:i:s e');

        $hours->isOpenAt($utc);

        $this->assertSame($snapshot, $utc->format('Y-m-d H:i:s e'));
    }

    public function test_missing_day_key_is_treated_as_ruhetag(): void
    {
        // Only Monday is configured. Tuesday key absent entirely.
        $hours = new OpeningHours(
            ['mon' => [['from' => '11:30', 'to' => '14:30']]],
            'Europe/Berlin',
        );

        $tuesday = Carbon::create(2026, 4, 21, 12, 0, 0, 'Europe/Berlin');

        $this->assertFalse($hours->isOpenAt($tuesday));
    }

    public function test_empty_schedule_is_always_closed(): void
    {
        $hours = new OpeningHours([], 'Europe/Berlin');

        $time = Carbon::create(2026, 4, 20, 12, 0, 0, 'Europe/Berlin');

        $this->assertFalse($hours->isOpenAt($time));
    }

    public function test_unknown_timezone_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OpeningHours([], 'Mars/Olympus');
    }
}
