<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Output format for the PRD-009 reservation export pipeline.
 *
 * `Csv` is the default — small, machine-friendly, opens cleanly in
 * Excel with the UTF-8 BOM the generator writes. `Pdf` is the
 * presentation copy (printed daily-list, stapled to a clipboard
 * shift hand-off). Sibling issue #235 layers the validated form
 * request on top; sibling issue #237/#238 add the actual generators.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Pdf = 'pdf';

    /**
     * Human-readable label used in the dashboard's export
     * dropdown and the audit-log surface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Pdf => 'PDF',
        };
    }

    /**
     * Outbound `Content-Type` header for both the sync streamed
     * download and the signed async URL response.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Pdf => 'application/pdf',
        };
    }

    /**
     * Filename extension (no leading dot) used when composing the
     * download filename and persisting the artefact on the storage
     * disk.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Csv => 'csv',
            self::Pdf => 'pdf',
        };
    }
}
