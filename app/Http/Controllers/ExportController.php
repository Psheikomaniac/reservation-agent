<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Exports\ExportRequest;
use App\Models\ExportAudit;
use App\Services\Exports\ExportDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * PRD-009 § Async-Generator: serves the binary artefact behind a
 * signed, time-limited URL. Two layers of access control on top of
 * Laravel's signed-URL middleware:
 *
 *   1. The audit row's `expires_at` must still be in the future
 *      (defends against replay if the link gets shared after the
 *      24 h window).
 *   2. The authenticated user must be the audit's owner — even a
 *      valid signed URL must not let a sibling operator pull the
 *      file (operator-vs-operator privacy inside the same
 *      restaurant, plus cross-tenant defense in depth).
 */
final class ExportController extends Controller
{
    /**
     * Operator-triggered export. Sync path (≤ 100 records) returns
     * a `StreamedResponse` the browser downloads directly; async
     * path (> 100) queues `ExportReservationsJob` and returns a
     * `RedirectResponse` carrying the flash message the dashboard
     * surfaces. Tenant guard is here (not in the FormRequest) so
     * the same convention as `AnalyticsController` applies — a
     * tenantless user → 404, not 403.
     */
    public function store(ExportRequest $request, ExportDispatcher $dispatcher): StreamedResponse|RedirectResponse
    {
        $user = $request->user();
        if ($user === null || $user->restaurant_id === null) {
            throw new NotFoundHttpException;
        }

        return $dispatcher->dispatch(
            $request->exportFormat(),
            $request->filterSnapshot(),
            $user,
        );
    }

    public function download(Request $request, int $token): StreamedResponse
    {
        $audit = ExportAudit::query()->find($token);
        if ($audit === null) {
            throw new NotFoundHttpException;
        }

        if (Auth::id() !== $audit->user_id) {
            throw new AccessDeniedHttpException(
                'Dieser Export-Link gehört einem anderen Benutzer.'
            );
        }

        if ($audit->expires_at === null || $audit->expires_at->isPast()) {
            throw new AccessDeniedHttpException(
                'Der Download-Link ist abgelaufen. Bitte den Export neu starten.'
            );
        }

        $path = $audit->storage_path;
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            throw new NotFoundHttpException(
                'Die Export-Datei wurde bereits aus dem Speicher entfernt.'
            );
        }

        // Stamp the first-fetch timestamp once. The PRD-009 audit
        // contract is "did the operator pick this link up?" — a
        // refresh or a second click on the same emailed URL must
        // not overwrite the first-access record.
        if ($audit->downloaded_at === null) {
            $audit->forceFill(['downloaded_at' => now()])->save();
        }

        $filename = basename($path);
        $mimeType = $audit->format->mimeType();

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => $mimeType,
        ]);
    }
}
