<?php

namespace App\Models;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\Scopes\RestaurantScope;
use Database\Factories\ReservationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
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
        'email_message_id',
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
     * @return HasMany<ReservationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ReservationMessage::class);
    }

    /**
     * @return HasOne<ReservationReply, $this>
     */
    public function latestReply(): HasOne
    {
        return $this->hasOne(ReservationReply::class)->latestOfMany('created_at');
    }

    /**
     * Apply the dashboard filter set validated by DashboardFilterRequest.
     *
     * Every field is mapped explicitly — no dynamic column/operator
     * construction — so only keys the FormRequest approves can influence
     * the query.
     *
     * @param  Builder<self>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<self>
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (! empty($filters['status'])) {
            $query->whereIn('status', $filters['status']);
        }

        if (! empty($filters['source'])) {
            $query->whereIn('source', $filters['source']);
        }

        if (! empty($filters['from'])) {
            $query->where('desired_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('desired_at', '<=', $filters['to']);
        }

        if (! empty($filters['q'])) {
            $needle = '%'.$filters['q'].'%';
            $query->where(function (Builder $inner) use ($needle): void {
                $inner->where('guest_name', 'like', $needle)
                    ->orWhere('guest_email', 'like', $needle);
            });
        }

        return $query;
    }
}
