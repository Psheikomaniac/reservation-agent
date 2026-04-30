<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Enums\ExportFormat;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Exports\Contracts\ExportGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF export generator for the PRD-009 pipeline.
 *
 * Plain printable table — no marketing, no brand competition with
 * the restaurant's own materials. Restaurant name as H1, a meta
 * line with the export timestamp, an optional filter summary, and
 * the table proper. DejaVu Sans is used so German umlauts render
 * cleanly in dompdf without falling back to glyph boxes.
 *
 * Like the CSV path, sync streaming and async disk-write share
 * the same renderer. The Blade template lives in
 * `resources/views/exports/reservations-pdf.blade.php` so future
 * design tweaks don't require touching this class.
 */
final class PdfExportGenerator implements ExportGenerator
{
    /**
     * @var array<string, string>
     */
    private const array STATUS_LABELS = [
        'new' => 'Neu',
        'in_review' => 'In Bearbeitung',
        'replied' => 'Beantwortet',
        'confirmed' => 'Bestätigt',
        'declined' => 'Abgelehnt',
        'cancelled' => 'Storniert',
    ];

    /**
     * @var array<string, string>
     */
    private const array SOURCE_LABELS = [
        'web_form' => 'Webformular',
        'email' => 'E-Mail',
    ];

    public function generateSync(
        ExportFormat $format,
        Restaurant $restaurant,
        array $filters,
    ): StreamedResponse {
        if ($format !== ExportFormat::Pdf) {
            throw new InvalidArgumentException(sprintf(
                'PdfExportGenerator can only emit PDF; got %s.',
                $format->value,
            ));
        }

        $payload = $this->renderPdf($restaurant, $filters);
        $filename = $this->filename($restaurant);

        return new StreamedResponse(
            function () use ($payload): void {
                echo $payload;
            },
            200,
            [
                'Content-Type' => $format->mimeType(),
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ],
        );
    }

    /**
     * Async-path entry point: returns the raw PDF binary so
     * `ExportReservationsJob` (#239) can persist it to its chosen
     * disk path. Keeping the disk concern in the job mirrors the
     * CSV generator's `renderToString()`.
     *
     * @param  array<string, mixed>  $filters
     */
    public function renderToString(Restaurant $restaurant, array $filters): string
    {
        return $this->renderPdf($restaurant, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function renderPdf(Restaurant $restaurant, array $filters): string
    {
        $timezone = $restaurant->timezone ?? config('app.timezone');

        $rows = ReservationRequest::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)
            ->filter($filters)
            ->orderByDesc('desired_at')
            ->get()
            ->map(fn (ReservationRequest $request) => $this->row($request, $timezone))
            ->all();

        $pdf = Pdf::loadView('exports.reservations-pdf', [
            'restaurant' => $restaurant,
            'generatedAt' => Carbon::now($timezone),
            'filterSummary' => $this->summarizeFilters($filters),
            'rows' => $rows,
        ]);

        $pdf->setPaper('a4', 'portrait');

        return $pdf->output();
    }

    /**
     * @return array<string, string>
     */
    private function row(ReservationRequest $request, string $timezone): array
    {
        $desired = $request->desired_at?->copy()->setTimezone($timezone);

        $status = $request->status instanceof ReservationStatus
            ? $request->status->value
            : (string) $request->status;
        $source = $request->source instanceof ReservationSource
            ? $request->source->value
            : (string) $request->source;

        return [
            'date' => $desired?->format('d.m.Y') ?? '—',
            'time' => $desired?->format('H:i') ?? '—',
            'party_size' => (string) ($request->party_size ?? 0),
            'name' => (string) ($request->guest_name ?? ''),
            'status' => self::STATUS_LABELS[$status] ?? $status,
            'source' => self::SOURCE_LABELS[$source] ?? $source,
        ];
    }

    /**
     * Produce a list of human-readable filter lines for the
     * template's meta block. Empty filters yield an empty array,
     * which the template uses to skip the filter row entirely.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function summarizeFilters(array $filters): array
    {
        $lines = [];

        if (! empty($filters['status'])) {
            $labels = array_map(
                fn (string $key) => self::STATUS_LABELS[$key] ?? $key,
                (array) $filters['status'],
            );
            $lines[] = 'Status: '.implode(', ', $labels);
        }

        if (! empty($filters['source'])) {
            $labels = array_map(
                fn (string $key) => self::SOURCE_LABELS[$key] ?? $key,
                (array) $filters['source'],
            );
            $lines[] = 'Quelle: '.implode(', ', $labels);
        }

        if (! empty($filters['from'])) {
            $lines[] = 'von '.$filters['from'];
        }

        if (! empty($filters['to'])) {
            $lines[] = 'bis '.$filters['to'];
        }

        if (! empty($filters['q'])) {
            $lines[] = 'Suche: "'.$filters['q'].'"';
        }

        return $lines;
    }

    private function filename(Restaurant $restaurant): string
    {
        $slug = $restaurant->slug !== null && $restaurant->slug !== ''
            ? $restaurant->slug
            : 'export';

        return sprintf(
            'reservations-%s-%s.pdf',
            $slug,
            Carbon::now($restaurant->timezone ?? config('app.timezone'))->format('Y-m-d_H-i'),
        );
    }
}
