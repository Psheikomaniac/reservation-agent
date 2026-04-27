<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReservationReply;
use App\Models\User;

final class ReservationReplyPolicy
{
    public function view(User $user, ReservationReply $reply): bool
    {
        return $reply->reservationRequest?->restaurant_id === $user->restaurant_id
            && $user->restaurant_id !== null;
    }

    public function approve(User $user, ReservationReply $reply): bool
    {
        return $this->view($user, $reply);
    }
}
