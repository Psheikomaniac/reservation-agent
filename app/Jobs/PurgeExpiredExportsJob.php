<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ExportAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Six-hourly cleanup of stale export artefacts (PRD-009 §
 * Audit & Cleanup).
 *
 * For every audit row whose `expires_at` is in the past **and**
 * still has a `storage_path`, the file is deleted from disk and
 * the column nulled out — but the audit row stays. GDPR access
 * requests need the audit record (who exported what, when) even
 * after the binary artefact is gone.
 *
 * Idempotent: rows whose `storage_path` is already `null` are
 * filtered out at the query layer, so a second run within the
 * same six-hour window does no extra work.
 */
class PurgeExpiredExportsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const string DISK = 'local';

    private const int CHUNK_SIZE = 100;

    public function handle(): void
    {
        ExportAudit::query()
            ->whereNotNull('storage_path')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($chunk): void {
                foreach ($chunk as $audit) {
                    if ($audit->storage_path !== null) {
                        Storage::disk(self::DISK)->delete($audit->storage_path);
                    }

                    $audit->forceFill(['storage_path' => null])->save();
                }
            });
    }
}
