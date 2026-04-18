<?php

namespace App\Models;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\Scopes\RestaurantScope;
use Database\Factories\ReservationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ScopedBy(RestaurantScope::class)]
class ReservationRequest extends Model
{
    /** @use HasFactory<ReservationRequestFactory> */
    use HasFactory;

    public const int MAX_PARTY_SIZE = 20;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'restaurant_id',
        'source',
        'status',
        'guest_name',
        'guest_email',
        'guest_phone',
        'party_size',
        'desired_at',
        'message',
        'raw_payload',
        'needs_manual_review',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ReservationSource::class,
            'status' => ReservationStatus::class,
            'party_size' => 'integer',
            'desired_at' => 'datetime',
            'raw_payload' => 'encrypted:array',
            'needs_manual_review' => 'boolean',
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
     * @return HasMany<ReservationReply, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ReservationReply::class);
    }

    /**
     * @return HasOne<ReservationReply, $this>
     */
    public function latestReply(): HasOne
    {
        return $this->hasOne(ReservationReply::class)->latestOfMany('created_at');
    }
}
