<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Models\ReservationReply;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

final class RestaurantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::hasUser()) {
            return;
        }

        $restaurantId = Auth::user()?->restaurant_id;

        if ($restaurantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($model instanceof ReservationReply) {
            $builder->whereHas(
                'reservationRequest',
                fn (Builder $query) => $query->where('restaurant_id', $restaurantId)
            );

            return;
        }

        $builder->where($model->qualifyColumn('restaurant_id'), $restaurantId);
    }
}
