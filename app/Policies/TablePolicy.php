<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Table;
use App\Models\User;

/**
 * Authorization for table master data (PRD-011).
 *
 * Tenant isolation lives here, not in the controller: view/update/delete are
 * scoped to the user's own restaurant. Mutations additionally require the owner
 * role; staff may only read.
 */
final class TablePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->restaurant_id !== null;
    }

    public function view(User $user, Table $table): bool
    {
        return $this->belongsToSameRestaurant($user, $table);
    }

    public function create(User $user): bool
    {
        return $user->restaurant_id !== null && $user->role === UserRole::Owner;
    }

    public function update(User $user, Table $table): bool
    {
        return $this->belongsToSameRestaurant($user, $table) && $user->role === UserRole::Owner;
    }

    public function delete(User $user, Table $table): bool
    {
        return $this->belongsToSameRestaurant($user, $table) && $user->role === UserRole::Owner;
    }

    private function belongsToSameRestaurant(User $user, Table $table): bool
    {
        return $user->restaurant_id !== null && $user->restaurant_id === $table->restaurant_id;
    }
}
