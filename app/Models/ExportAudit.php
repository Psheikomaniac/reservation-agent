<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportFormat;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row for every reservation export the operator opens
 * (PRD-009 § Audit & Cleanup). Append-only — no `updated_at` —
 * because each export is a single point-in-time event.
 *
 * `storage_path` carries the disk path of the generated artefact
 * for the async path; the sync path (≤ 100 records, streamed
 * straight to the operator) leaves it null. The `PurgeExpiredExportsJob`
 * (sibling issue #241) deletes the file after `expires_at` and
 * nulls `storage_path` while keeping the audit row intact.
 *
 * `downloaded_at` is stamped the first time the operator follows
 * the signed download URL. The audit therefore answers both
 * "did this export ever happen?" and "did the operator actually
 * fetch it?".
 *
 * `filter_snapshot` is the exact dashboard filter set that was
 * frozen at export time, so reproducing the file (or honouring a
 * GDPR access request after the artefact is gone) doesn't depend
 * on the operator remembering which filters they had open.
 */
class ExportAudit extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'restaurant_id',
        'user_id',
        'format',
        'filter_snapshot',
        'record_count',
        'storage_path',
        'downloaded_at',
        'expires_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'filter_snapshot' => AsArrayObject::class,
            'record_count' => 'integer',
            'downloaded_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Single entry point the export pipeline uses to start a new
     * audit row. The user's restaurant supplies the tenant (one
     * user → one restaurant in V1.0); call sites don't have to
     * repeat that resolution. The async-path ExportReservationsJob
     * (sibling issue #239) updates `storage_path` and `expires_at`
     * once the artefact lands on disk.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function open(User $user, ExportFormat $format, array $filters, int $count): self
    {
        return self::create([
            'restaurant_id' => $user->restaurant_id,
            'user_id' => $user->id,
            'format' => $format->value,
            'filter_snapshot' => $filters,
            'record_count' => $count,
            'storage_path' => null,
            'downloaded_at' => null,
            'expires_at' => null,
            'created_at' => now(),
        ]);
    }
}
