<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs a warning when the URL the browser used to reach the app differs from
 * APP_URL. Ziggy serialises APP_URL into window.Ziggy, so a mismatch causes
 * Inertia <Link> clicks to navigate to the wrong origin (classic dev footgun
 * when APP_URL=http://localhost but `php artisan serve` runs on :8000).
 *
 * Active only in the `local` environment.
 */
final class WarnOnAppUrlMismatch
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local')) {
            $accessed = $request->getSchemeAndHttpHost();
            $configured = rtrim((string) config('app.url'), '/');

            if ($accessed !== '' && $accessed !== $configured) {
                Log::warning('APP_URL mismatch — Inertia/Ziggy links will point to the wrong origin. Update APP_URL in .env to match the URL you open in the browser.', [
                    'configured_app_url' => $configured,
                    'accessed_url' => $accessed,
                ]);
            }
        }

        return $next($request);
    }
}
