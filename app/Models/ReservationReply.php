<?php

namespace App\Models;

use App\Enums\ReservationReplyStatus;
use App\Enums\SendMode;
use App\Models\Scopes\RestaurantScope;
use Database\Factories\ReservationReplyFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy(RestaurantScope::class)]
class ReservationReply extends Model
{
    /** @use HasFactory<ReservationReplyFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reservation_request_id',
        'status',
        'body',
        'ai_prompt_snapshot',
        'approved_by',
        'approved_at',
        'sent_at',
        'error_message',
        'outbound_message_id',
        'is_fallback',
        'send_mode_at_creation',
        'shadow_compared_at',
        'shadow_was_modified',
        'auto_send_decision',
        'auto_send_scheduled_for',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationReplyStatus::class,
            'ai_prompt_snapshot' => 'array',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'is_fallback' => 'boolean',
            'send_mode_at_creation' => SendMode::class,
            'shadow_compared_at' => 'datetime',
            'shadow_was_modified' => 'boolean',
            'auto_send_decision' => 'array',
            'auto_send_scheduled_for' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReservationRequest, $this>
     */
    public function reservationRequest(): BelongsTo
    {
        return $this->belongsTo(ReservationRequest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
