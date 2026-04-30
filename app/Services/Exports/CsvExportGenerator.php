<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Enums\ExportFormat;
use App\Enums\ReservationReplyStatus;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Exports\Contracts\ExportGenerator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export generator for the PRD-009 pipeline.
 *
 * Excel-DE compatible: UTF-8 with BOM (so Excel doesn't mojibake
 * the German umlauts) plus the `;` separator (so Excel-DE parses
 * the columns correctly without an import wizard). German enum
 * labels per the PRD column schema.
 *
 * Sync path streams the file straight back to the operator's
 * browser; the async path (sibling issue #239) writes to disk
 * via `writeToDisk(...)` and notifies the operator with a
 * signed URL.
 */
final class CsvExportGenerator implements ExportGenerator
{
    private const string EXCEL_BOM = "\xEF\xBB\xBF";

    private const string DELIMITER = ';';

    /**
     * @var list<string>
     */
    private const array HEADER_ROW = [
        'ID',
        'Eingegangen',
        'Status',
        'Quelle',
        'Wunschdatum',
        'Personen',
        'Name',
        'E-Mail',
        'Telefon',
        'Manuelle Prüfung',
        'Letzte Antwort',
    ];

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

    /**
     * @var array<string, string>
     */
    private const array REPLY_STATUS_LABELS = [
        'draft' => 'Entwurf',
        'approved' => 'Freigegeben',
        'sent' => 'Versendet',
        'failed' => 'Versand fehlgeschlagen',
        'shadow' => 'Schatten-Modus',
        'scheduled_auto_send' => 'Auto-Versand geplant',
        'cancelled_auto' => 'Auto-Versand abgebrochen',
    ];

    public function generateSync(
        ExportFormat $format,
        Restaurant $restaurant,
        array $filters,
    ): StreamedResponse {
        if ($format !== ExportFormat::Csv) {
            throw new InvalidArgumentException(sprintf(
                'CsvExportGenerator can only emit CSV; got %s.',
                $format->value,
            ));
        }

        $payload = $this->renderCsv($restaurant, $filters);
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
     * Async-path entry point used by `ExportReservationsJob`
     * (#239): renders the CSV string so the job can persist it
     * to its chosen disk path. Returning the raw payload keeps
     * the disk concern in the job — easier to unit-test the
     * generator without spinning up a Storage fake.
     *
     * @param  array<string, mixed>  $filters
     */
    public function renderToString(Restaurant $restaurant, array $filters): string
    {
        return $this->renderCsv($restaurant, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function renderCsv(Restaurant $restaurant, array $filters): string
    {
        $writer = Writer::createFromString();
        $writer->setDelimiter(self::DELIMITER);
        $writer->insertOne(self::HEADER_ROW);

        $timezone = $restaurant->timezone ?? config('app.timezone');

        ReservationRequest::query()
            ->withoutGlobalScopes()
            ->where('restaurant_id', $restaurant->id)
            ->filter($filters)
            ->with(['latestReply'])
            ->orderByDesc('created_at')
            ->chunk(500, function ($chunk) use ($writer, $timezone): void {
                foreach ($chunk as $request) {
                    $writer->insertOne($this->row($request, $timezone));
                }
            });

        return self::EXCEL_BOM.$writer->toString();
    }

    /**
     * @return list<string>
     */
    private function row(ReservationRequest $request, string $timezone): array
    {
        $status = $request->status instanceof ReservationStatus
            ? $request->status->value
            : (string) $request->status;
        $source = $request->source instanceof ReservationSource
            ? $request->source->value
            : (string) $request->source;

        $latestReplyLabel = '';
        if ($request->relationLoaded('latestReply') && $request->latestReply !== null) {
            $replyStatus = $request->latestReply->status;
            $key = $replyStatus instanceof ReservationReplyStatus
                ? $replyStatus->value
                : (string) $replyStatus;
            $latestReplyLabel = self::REPLY_STATUS_LABELS[$key] ?? $key;
        }

        return [
            (string) $request->id,
            $this->formatDate($request->created_at, $timezone),
            self::STATUS_LABELS[$status] ?? $status,
            self::SOURCE_LABELS[$source] ?? $source,
            $this->formatDate($request->desired_at, $timezone),
            (string) ($request->party_size ?? 0),
            (string) ($request->guest_name ?? ''),
            (string) ($request->guest_email ?? ''),
            (string) ($request->guest_phone ?? ''),
            $request->needs_manual_review ? 'Ja' : 'Nein',
            $latestReplyLabel,
        ];
    }

    private function formatDate(?Carbon $value, string $timezone): string
    {
        if ($value === null) {
            return '';
        }

        return $value->copy()->setTimezone($timezone)->format('Y-m-d H:i');
    }

    private function filename(Restaurant $restaurant): string
    {
        $slug = $restaurant->slug !== null && $restaurant->slug !== ''
            ? $restaurant->slug
            : 'export';

        return sprintf(
            'reservations-%s-%s.csv',
            $slug,
            Carbon::now($restaurant->timezone ?? config('app.timezone'))->format('Y-m-d_H-i'),
        );
    }
}
