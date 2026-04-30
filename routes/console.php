<?php

declare(strict_types=1);

use App\Jobs\FetchReservationEmailsJob;
use App\Jobs\PruneFailedEmailImportsJob;
use App\Jobs\PurgeExpiredExportsJob;
use App\Jobs\SendDailyDigestJob;
use App\Models\Restaurant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Restaurant::query()
        ->whereNotNull('imap_host')
        ->each(fn (Restaurant $restaurant) => FetchReservationEmailsJob::dispatch($restaurant->id));
})
    ->name(FetchReservationEmailsJob::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::job(new PruneFailedEmailImportsJob)
    ->name(PruneFailedEmailImportsJob::class)
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::job(new PurgeExpiredExportsJob)
    ->name(PurgeExpiredExportsJob::class)
    ->everySixHours()
    ->withoutOverlapping();

// PRD-010 § Email-Digest. Hourly tick because restaurants in
// different timezones map "18:00" to different UTC moments — the job
// itself filters internally on the configured per-user time.
Schedule::job(new SendDailyDigestJob)
    ->name(SendDailyDigestJob::class)
    ->hourly()
    ->withoutOverlapping();
