<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\PruneFailedEmailImportsJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneFailedEmailImportsScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_daily_3am_schedule_with_overlap_protection(): void
    {
        $event = $this->findPruneScheduleEvent();

        $this->assertNotNull(
            $event,
            'Expected a scheduled job for PruneFailedEmailImportsJob to be registered in routes/console.php.',
        );
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertNotNull(
            $event->withoutOverlapping,
            'Expected the schedule to call withoutOverlapping() to prevent stacked runs.',
        );
    }

    private function findPruneScheduleEvent(): ?Event
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if ($event->description === PruneFailedEmailImportsJob::class) {
                return $event;
            }
        }

        return null;
    }
}
