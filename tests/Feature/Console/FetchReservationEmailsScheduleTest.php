<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\FetchReservationEmailsJob;
use App\Models\Restaurant;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class FetchReservationEmailsScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_five_minute_schedule_with_overlap_protection(): void
    {
        $event = $this->findFetchScheduleEvent();

        $this->assertNotNull(
            $event,
            'Expected a scheduled callback that dispatches FetchReservationEmailsJob to be registered in routes/console.php.',
        );
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertNotNull(
            $event->withoutOverlapping,
            'Expected the schedule to call withoutOverlapping() to prevent stacked runs.',
        );
    }

    public function test_it_dispatches_the_job_only_for_restaurants_with_imap_host(): void
    {
        Bus::fake([FetchReservationEmailsJob::class]);

        $withImap = Restaurant::factory()->create(['imap_host' => 'imap.example.com']);
        $withoutImap = Restaurant::factory()->create(['imap_host' => null]);

        $event = $this->findFetchScheduleEvent();
        $this->assertNotNull($event);

        $event->run($this->app);

        Bus::assertDispatched(
            FetchReservationEmailsJob::class,
            fn (FetchReservationEmailsJob $job) => $job->restaurantId === $withImap->id,
        );
        Bus::assertNotDispatched(
            FetchReservationEmailsJob::class,
            fn (FetchReservationEmailsJob $job) => $job->restaurantId === $withoutImap->id,
        );
        Bus::assertDispatchedTimes(FetchReservationEmailsJob::class, 1);
    }

    public function test_it_dispatches_nothing_when_no_restaurant_has_imap_configured(): void
    {
        Bus::fake([FetchReservationEmailsJob::class]);

        Restaurant::factory()->create(['imap_host' => null]);

        $event = $this->findFetchScheduleEvent();
        $this->assertNotNull($event);

        $event->run($this->app);

        Bus::assertNothingDispatched();
    }

    private function findFetchScheduleEvent(): ?CallbackEvent
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if ($event instanceof CallbackEvent && $event->description === FetchReservationEmailsJob::class) {
                return $event;
            }
        }

        return null;
    }
}
