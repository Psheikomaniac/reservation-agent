<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReservationRequestReceived;
use App\Jobs\GenerateReservationReplyJob;

/**
 * Bridges the domain event `ReservationRequestReceived` to the queued
 * draft-generation job. Lives in its own class (not a closure listener)
 * so the wiring can be auto-discovered and unit-tested without booting
 * the queue.
 */
final class GenerateReservationReplyListener
{
    public function handle(ReservationRequestReceived $event): void
    {
        GenerateReservationReplyJob::dispatch($event->request->id);
    }
}
