<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Policies\ReservationRequestPolicy;
use App\Policies\RestaurantPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public static array $policies = [
        Restaurant::class => RestaurantPolicy::class,
        ReservationRequest::class => ReservationRequestPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::$policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
