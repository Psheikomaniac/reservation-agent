<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

final class InvitationPolicy
{
    /**
     * Only an owner of a restaurant may invite staff.
     */
    public function create(User $user): bool
    {
        return $user->restaurant_id !== null && $user->role === UserRole::Owner;
    }
}
