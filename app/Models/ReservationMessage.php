<?php

namespace App\Models;

use App\Enums\MessageDirection;
use Database\Factories\ReservationMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReservationMessage extends Model
{
    /** @use HasFactory<ReservationMessageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reservation_request_id',
        'direction',
        'message_id',
        'in_reply_to',
        'references',
        'subject',
        'from_address',
        'to_address',
        'body_plain',
        'raw_headers',
        'sent_at',
        'received_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReservationRequest, $this>
     */
    public function reservationRequest(): BelongsTo
    {
        return $this->belongsTo(ReservationRequest::class);
    }
}
