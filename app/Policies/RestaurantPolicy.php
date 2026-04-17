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
}
