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

    /**
     * Cancelling a scheduled auto-send is operator-level — the same
     * tenancy check `view`/`approve` use applies. We don't gate by
     * role here: any operator (Owner or Staff) on the right restaurant
     * can hit the cancel button while the cancel window is open.
     */
    public function cancelAutoSend(User $user, ReservationReply $reply): bool
    {
        return $this->view($user, $reply);
    }
}
