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

    /**
     * Marking a shadow reply as compared is a side-effect on view —
     * same tenancy gate, no role distinction. The dashboard fires
     * this on first drawer open for a shadow reply; cross-restaurant
     * users still must not be able to flip the timestamp on someone
     * else's data.
     */
    public function markShadowCompared(User $user, ReservationReply $reply): bool
    {
        return $this->view($user, $reply);
    }
}
