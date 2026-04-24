<?php

declare(strict_types=1);

use App\Jobs\FetchReservationEmailsJob;
use App\Jobs\PruneFailedEmailImportsJob;
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
