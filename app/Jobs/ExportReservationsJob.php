<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Models\ExportAudit;
use App\Services\Exports\ExportDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Async export pipeline (PRD-009 § Controller + ExportDispatcher).
 *
 * Queued by {@see ExportDispatcher} when
 * the filtered record count exceeds the sync threshold (100).
 * The job runs the heavy CSV / PDF generation, persists the
 * artefact to the storage disk, stamps `storage_path` +
 * `expires_at` on the supplied audit row, and notifies the
 * operator with a signed download URL via `ExportReadyMail`.
 *
 * Issue #236 wires the dispatch path; the actual `handle()`
 * implementation lands in sibling issue #239 along with the
 * mail + signed-URL controller.
 */
class ExportReservationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * `restaurantId` is carried explicitly so the worker can
     * re-apply tenant isolation: the queue runs without an
     * authenticated user, which makes `RestaurantScope`
     * short-circuit and the otherwise-implicit `where
     * restaurant_id = ?` predicate disappear. The #239 handler
     * must therefore call `withoutGlobalScope(RestaurantScope::class)`
     * + `where('restaurant_id', $this->restaurantId)` (same
     * pattern the analytics aggregator uses).
     *
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly int $exportAuditId,
        public readonly ExportFormat $format,
        public readonly array $filters,
        public readonly int $userId,
        public readonly int $restaurantId,
    ) {}

    public function handle(): void
    {
        // Implementation lands in #239 (job + mail + signed URL).
        // The dispatch path of #236 only needs the constructor +
        // interface; making `handle()` a no-op here keeps the job
        // dispatchable end-to-end without producing an artefact
        // until the next sibling issue fills it in.
        ExportAudit::query()
            ->whereKey($this->exportAuditId)
            ->exists();
    }
}
