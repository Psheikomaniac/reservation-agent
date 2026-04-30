<?php

declare(strict_types=1);

namespace App\Services\Exports\Contracts;

use App\Enums\ExportFormat;
use App\Models\Restaurant;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Concrete generators (CSV via league/csv in #237, PDF via
 * dompdf in #238) implement this contract. The dispatcher
 * delegates to it for the sync (≤ 100 records) path; the async
 * path (#239) writes to disk via the same shape but wrapped in
 * a job. Keeping the interface tight means the dispatcher and
 * the job don't have to know which format they're producing.
 */
interface ExportGenerator
{
    /**
     * Stream the export back to the operator's browser. The
     * filtered query already runs through `RestaurantScope`, so
     * the generator never has to enforce tenant isolation.
     *
     * @param  array<string, mixed>  $filters  Validated filter snapshot.
     */
    public function generateSync(
        ExportFormat $format,
        Restaurant $restaurant,
        array $filters,
    ): StreamedResponse;

    /**
     * Render the export as a binary string. Used by the async
     * pipeline (`ExportReservationsJob`) — the job decides
     * which disk + path to persist to, the generator only
     * concerns itself with producing the bytes.
     *
     * @param  array<string, mixed>  $filters
     */
    public function renderBytes(
        ExportFormat $format,
        Restaurant $restaurant,
        array $filters,
    ): string;
}
