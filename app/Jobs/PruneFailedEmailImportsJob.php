<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FailedEmailImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

final class PruneFailedEmailImportsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        if (! config('reservations.failed_email_imports.prune.enabled')) {
            return;
        }

        $retentionDays = (int) config('reservations.failed_email_imports.prune.retention_days');

        if ($retentionDays < 1) {
            return;
        }

        $cutoff = Carbon::now()->subDays($retentionDays);

        $query = FailedEmailImport::withoutGlobalScopes()
            ->where('created_at', '<', $cutoff);

        $oldestDeletedAt = $query->clone()->min('created_at');
        $deletedCount = $query->delete();

        Log::info('reservation.failed_email_imports.pruned', [
            'deleted_count' => $deletedCount,
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toIso8601String(),
            'oldest_deleted_at' => $oldestDeletedAt === null
                ? null
                : Carbon::parse($oldestDeletedAt)->toIso8601String(),
        ]);
    }
}
