<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row for every auto-send decision (PRD-007).
 *
 * The table has no `updated_at` and no soft-delete: audits are either
 * written or absent. `decision` is the string-literal outcome (`manual`
 * | `shadow` | `auto_send` | `cancelled_auto`); the in-flight value
 * object `AutoSendDecision` covers the first three, while
 * `cancelled_auto` is reserved for late cancellations (killswitch
 * during cancel-window, hard-gate slipping in late) where no actual
 * send happens.
 */
class AutoSendAudit extends Model
{
    public const string DECISION_MANUAL = 'manual';

    public const string DECISION_SHADOW = 'shadow';

    public const string DECISION_AUTO_SEND = 'auto_send';

    public const string DECISION_CANCELLED_AUTO = 'cancelled_auto';

    public const string REASON_KILLSWITCH_DURING_WINDOW = 'killswitch_during_window';

    /** Prefix combined with the late hard-gate reason, e.g. `hard_gate_late:short_notice`. */
    public const string REASON_HARD_GATE_LATE_PREFIX = 'hard_gate_late:';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reservation_reply_id',
        'restaurant_id',
        'send_mode',
        'decision',
        'reason',
        'triggered_by_user_id',
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
     * @return BelongsTo<ReservationReply, $this>
     */
    public function reservationReply(): BelongsTo
    {
        return $this->belongsTo(ReservationReply::class);
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
