<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\SendDailyDigestJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendDailyDigestScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_an_hourly_schedule_with_overlap_protection(): void
    {
        $event = $this->findScheduleEvent();

        $this->assertNotNull(
            $event,
            'Expected a scheduled job for SendDailyDigestJob to be registered in routes/console.php.',
        );
        // Hourly tick: minute 0 of every hour. The per-user clock match
        // happens inside the job for multi-timezone correctness.
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertNotNull(
            $event->withoutOverlapping,
            'Expected the schedule to call withoutOverlapping() to prevent stacked runs.',
        );
    }

    private function findScheduleEvent(): ?Event
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if ($event->description === SendDailyDigestJob::class) {
                return $event;
            }
        }

        return null;
    }
}
