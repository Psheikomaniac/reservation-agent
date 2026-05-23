<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only, PII-FREE audit row for GDPR self-service actions (PRD-015).
 *
 * Records only that an access/erasure action happened in a restaurant and
 * when — `action` (`view` | `delete` | `owner_bulk_delete`) plus
 * `restaurant_id` and `created_at`. It deliberately stores no guest email,
 * reservation id, IP or user-agent: the table proves the *volume* of
 * requests per restaurant, not *who* did *what*. That separation is what
 * keeps the audit data itself outside the erasure claim it records.
 *
 * Writer pattern mirrors {@see AutoSendAudit}: a single static `record()`,
 * `$timestamps = false`, no soft-deletes.
 */
class GdprAudit extends Model
{
    public const string ACTION_VIEW = 'view';

    public const string ACTION_DELETE = 'delete';

    public const string ACTION_OWNER_BULK_DELETE = 'owner_bulk_delete';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action',
        'restaurant_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * Single writer for GDPR audit rows. Call sites pass only the action and
     * the tenant — never any guest-identifying data, by design.
     */
    public static function record(string $action, int $restaurantId): self
    {
        return self::create([
            'action' => $action,
            'restaurant_id' => $restaurantId,
            'created_at' => now(),
        ]);
    }
}
