<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Mail\ExportReadyMail;
use App\Models\ExportAudit;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Exports\Contracts\ExportGenerator;
use App\Services\Exports\ExportDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Async export pipeline (PRD-009 § Async-Generator).
 *
 * Queued by {@see ExportDispatcher} when
 * the filtered record count exceeds the sync threshold (100).
 * The job:
 *   1. Re-applies tenant isolation manually (the worker has no
 *      authenticated user, so `RestaurantScope` short-circuits).
 *   2. Asks the format-routing `ExportGenerator` for the binary
 *      payload (CSV / PDF) and persists it to
 *      `storage/app/exports/{user_id}/{filename}` on the local
 *      disk.
 *   3. Stamps `storage_path` + `expires_at` (24 h shelf life)
 *      on the audit row.
 *   4. Builds a signed `exports.download` URL valid for the same
 *      24 h and emails it to the operator via {@see ExportReadyMail}.
 *
 * `PurgeExpiredExportsJob` (#241) deletes the file after
 * `expires_at`, leaving the audit row intact for GDPR.
 */
class ExportReservationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const int SHELF_LIFE_HOURS = 24;

    private const string DISK = 'local';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly int $exportAuditId,
        public readonly ExportFormat $format,
        public readonly array $filters,
        public readonly int $userId,
        public readonly int $restaurantId,
    ) {}

    public function handle(ExportGenerator $generator): void
    {
        $audit = ExportAudit::query()->find($this->exportAuditId);
        if ($audit === null) {
            // Operator deleted the audit row out from under us
            // (test fixture cleanup, manual SQL, etc.). Nothing
            // to deliver — fail silently so the queue doesn't
            // retry forever.
            return;
        }

        $user = User::query()->find($this->userId);
        $restaurant = Restaurant::query()->find($this->restaurantId);
        if ($user === null || $restaurant === null) {
            throw new RuntimeException(sprintf(
                'ExportReservationsJob #%d cannot resolve user (%d) or restaurant (%d).',
                $audit->id,
                $this->userId,
                $this->restaurantId,
            ));
        }

        $payload = $generator->renderBytes($this->format, $restaurant, $this->filters);

        $path = sprintf(
            'exports/%d/%d-%s.%s',
            $user->id,
            $audit->id,
            now()->format('Ymd-His'),
            $this->format->extension(),
        );

        Storage::disk(self::DISK)->put($path, $payload);

        $expiresAt = now()->addHours(self::SHELF_LIFE_HOURS);

        $audit->forceFill([
            'storage_path' => $path,
            'expires_at' => $expiresAt,
        ])->save();

        $downloadUrl = URL::temporarySignedRoute(
            'exports.download',
            $expiresAt,
            ['token' => $audit->id],
        );

        Mail::to($user->email)->send(new ExportReadyMail(
            downloadUrl: $downloadUrl,
            format: $this->format,
            expiresAt: $expiresAt,
            recordCount: $audit->record_count,
        ));
    }
}
