<?php

namespace Tests\Unit\Events;

use App\Events\ReservationRequestReceived;
use App\Models\ReservationRequest;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReservationRequestReceivedTest extends TestCase
{
    public function test_event_carries_the_reservation_request(): void
    {
        $reservation = new ReservationRequest(['guest_name' => 'Alice Example']);

        $event = new ReservationRequestReceived($reservation);

        $this->assertSame($reservation, $event->request);
    }

    public function test_event_is_dispatchable(): void
    {
        Event::fake();
        $reservation = new ReservationRequest(['guest_name' => 'Alice Example']);

        ReservationRequestReceived::dispatch($reservation);

        Event::assertDispatched(
            ReservationRequestReceived::class,
            fn (ReservationRequestReceived $event) => $event->request === $reservation
        );
    }
}
