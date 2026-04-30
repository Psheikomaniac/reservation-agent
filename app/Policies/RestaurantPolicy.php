<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;

final class RestaurantPolicy
{
    public function view(User $user, Restaurant $restaurant): bool
    {
        return $user->restaurant_id === $restaurant->id;
    }

    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->restaurant_id === $restaurant->id;
    }

    public function manage(User $user, Restaurant $restaurant): bool
    {
        return $user->restaurant_id === $restaurant->id
            && $user->role === UserRole::Owner;
    }

    /**
     * Send-mode changes (manual ↔ shadow ↔ auto and the killswitch) are
     * security-relevant: in `auto` mode the system mails guests without
     * operator confirmation. PRD-007 reserves this surface for owners —
     * staff users get a 403 even on their own restaurant.
     */
    public function manageSendMode(User $user, Restaurant $restaurant): bool
    {
        return $user->restaurant_id === $restaurant->id
            && $user->role === UserRole::Owner;
    }
}
