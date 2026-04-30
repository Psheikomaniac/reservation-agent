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
}
