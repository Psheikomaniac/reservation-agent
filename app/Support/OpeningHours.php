<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Restaurant;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Value object around the JSON opening-hours schedule.
 *
 * Schedule shape (per PRD-001):
 *
 *     [
 *         'mon' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
 *         'tue' => [],
 *         ...
 *     ]
 *
 * Boundary convention: each block is a half-open interval [from, to).
 * At `from` the restaurant is OPEN, at `to` it is already CLOSED. This
 * allows two adjacent blocks (e.g. `14:30` end of lunch, `14:30` start
 * of dinner) to coexist without ambiguity.
 */
final class OpeningHours
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * @param  array<string, array<int, array{from: string, to: string}>>  $schedule
     */
    public function __construct(
        private readonly array $schedule,
        private readonly string $timezone,
    ) {
        try {
            new DateTimeZone($this->timezone);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Unknown timezone: {$this->timezone}", previous: $e);
        }
    }

    public static function fromRestaurant(Restaurant $restaurant): self
    {
        /** @var array<string, array<int, array{from: string, to: string}>> $schedule */
        $schedule = $restaurant->opening_hours ?? [];

        return new self($schedule, $restaurant->timezone);
    }

    public function isOpenAt(CarbonInterface $time): bool
    {
        $local = $time->copy()->setTimezone($this->timezone);

        $dayKey = self::DAY_KEYS[(int) $local->dayOfWeek];
        $blocks = $this->schedule[$dayKey] ?? [];

        $minutes = $local->hour * 60 + $local->minute;

        foreach ($blocks as $block) {
            $from = $this->toMinutes($block['from']);
            $to = $this->toMinutes($block['to']);

            if ($minutes >= $from && $minutes < $to) {
                return true;
            }
        }

        return false;
    }

    private function toMinutes(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);

        return ((int) $h) * 60 + (int) $m;
    }
}
