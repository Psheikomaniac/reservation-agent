<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Enums\ExportFormat;
use App\Http\Requests\Exports\ExportRequest;
use App\Jobs\ExportReservationsJob;
use App\Models\ExportAudit;
use App\Models\ReservationRequest;
use App\Models\User;
use App\Services\Exports\Contracts\ExportGenerator;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Decision layer for the PRD-009 export pipeline.
 *
 * Counts the filtered set once, opens an `ExportAudit` row, and
 * routes the work to either:
 *
 *   - the **sync** generator (≤ 100 records) — streams the
 *     artefact back to the operator's browser, no disk write,
 *     no email roundtrip; or
 *   - the **async** job (> 100 records) — queues
 *     {@see ExportReservationsJob} which (per #239) renders the
 *     artefact on disk and emails a signed download URL.
 *
 * Tenant isolation:
 *   - **Sync path**: implicit. The count + generator run with the
 *     authenticated user, so the global `RestaurantScope` applies
 *     the `where restaurant_id = ?` predicate.
 *   - **Async path**: must be explicit. The queue worker has no
 *     authenticated user, so `RestaurantScope` short-circuits;
 *     the job carries `restaurantId` and re-applies the predicate
 *     via `withoutGlobalScope(RestaurantScope::class)` (same pattern
 *     the analytics aggregator uses). #239's handler relies on it.
 *
 * The audit row's `restaurant_id` is resolved from the user.
 */
final readonly class ExportDispatcher
{
    /**
     * Threshold below which the export is streamed synchronously.
     * Above this, the operator gets a flash message and an email.
     */
    public const int SYNC_THRESHOLD = 100;

    public function __construct(
        private ExportGenerator $generator,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  Validated filter snapshot from {@see ExportRequest}.
     */
    public function dispatch(
        ExportFormat $format,
        array $filters,
        User $user,
    ): StreamedResponse|RedirectResponse {
        $count = ReservationRequest::query()->filter($filters)->count();
        $audit = ExportAudit::open($user, $format, $filters, $count);

        if ($count <= self::SYNC_THRESHOLD) {
            return $this->generator->generateSync(
                $format,
                $user->restaurant,
                $filters,
            );
        }

        ExportReservationsJob::dispatch(
            $audit->id,
            $format,
            $filters,
            $user->id,
            (int) $user->restaurant_id,
        );

        return back()->with(
            'flash.export',
            sprintf(
                'Export läuft im Hintergrund — du bekommst gleich eine E-Mail mit dem %s-Download (%d Einträge).',
                $format->label(),
                $count,
            ),
        );
    }
}
