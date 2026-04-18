<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ReservationRequest;
use Illuminate\Foundation\Events\Dispatchable;

final class ReservationRequestReceived
{
    use Dispatchable;

    public function __construct(public readonly ReservationRequest $request) {}
}
