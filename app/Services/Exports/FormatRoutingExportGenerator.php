<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Enums\ExportFormat;
use App\Models\Restaurant;
use App\Services\Exports\Contracts\ExportGenerator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Format-aware composite generator. Holds one instance per
 * concrete format and dispatches sync/async calls by the
 * `ExportFormat` enum the dispatcher and the queue handler pass
 * in. Lets the rest of the pipeline depend on a single
 * `ExportGenerator` contract while keeping the format-specific
 * code (Excel-DE compatibility, dompdf rendering) properly
 * isolated.
 */
final readonly class FormatRoutingExportGenerator implements ExportGenerator
{
    public function __construct(
        private CsvExportGenerator $csv,
        private PdfExportGenerator $pdf,
    ) {}

    public function generateSync(
        ExportFormat $format,
        Restaurant $restaurant,
        array $filters,
    ): StreamedResponse {
        return match ($format) {
            ExportFormat::Csv => $this->csv->generateSync($format, $restaurant, $filters),
            ExportFormat::Pdf => $this->pdf->generateSync($format, $restaurant, $filters),
        };
    }

    public function renderBytes(
        ExportFormat $format,
        Restaurant $restaurant,
        array $filters,
    ): string {
        return match ($format) {
            ExportFormat::Csv => $this->csv->renderBytes($format, $restaurant, $filters),
            ExportFormat::Pdf => $this->pdf->renderBytes($format, $restaurant, $filters),
        };
    }
}
