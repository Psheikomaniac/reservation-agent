<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\SendDailyDigestJob;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class DailyDigestScheduleTest extends TestCase
{
    public function test_send_daily_digest_job_is_registered_in_the_hourly_schedule(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains((string) $event->description, SendDailyDigestJob::class));

        $this->assertCount(
            1,
            $events,
            'Expected exactly one scheduled SendDailyDigestJob event in routes/console.php.',
        );

        $event = $events->first();

        // `hourly()` resolves to "0 * * * *" — at minute zero of every hour.
        // Asserting the cron expression rather than the call site keeps the
        // test focused on operator-visible behaviour: the digest fan-out
        // ticks once per hour, not twice, not every minute.
        $this->assertSame('0 * * * *', $event->expression);
    }
}
