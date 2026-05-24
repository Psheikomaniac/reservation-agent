<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes authenticated members of a not-yet-live restaurant away from the
 * normal app: owners to the setup wizard, staff to a read-only placeholder.
 * Guests and members of live restaurants pass straight through.
 */
final class EnsureOnboardingComplete
{
    /**
     * Route names that must stay reachable while onboarding is incomplete.
     *
     * @var list<string>
     */
    private const EXEMPT = [
        'onboarding.wizard',
        'onboarding.restaurant.update',
        'onboarding.hours.update',
        'onboarding.tables.store',
        'onboarding.tonality.update',
        'onboarding.team.store',
        'onboarding.pending',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $user->restaurant_id === null) {
            return $next($request);
        }

        $restaurant = $user->restaurant;

        if ($restaurant === null || $restaurant->isLive()) {
            return $next($request);
        }

        if ($request->routeIs(self::EXEMPT)) {
            return $next($request);
        }

        $target = $user->role === UserRole::Owner
            ? route('onboarding.wizard')
            : route('onboarding.pending');

        return redirect()->to($target);
    }
}
