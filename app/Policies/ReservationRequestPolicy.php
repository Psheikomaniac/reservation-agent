<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReservationRequest;
use App\Models\User;

final class ReservationRequestPolicy
{
    public function view(User $user, ReservationRequest $request): bool
    {
        return $user->restaurant_id === $request->restaurant_id;
    }

    public function update(User $user, ReservationRequest $request): bool
    {
        return $user->restaurant_id === $request->restaurant_id;
    }

    public function delete(User $user, ReservationRequest $request): bool
    {
        return $user->restaurant_id === $request->restaurant_id;
    }

    public function bulkUpdate(User $user): bool
    {
        return $user->restaurant_id !== null;
    }
}
