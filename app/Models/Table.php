<?php

namespace App\Models;

use App\Models\Scopes\RestaurantScope;
use Database\Factories\TableFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ScopedBy(RestaurantScope::class)]
class Table extends Model
{
    /** @use HasFactory<TableFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'restaurant_id',
        'label',
        'seats',
        'room_tag',
        'sort_order',
        'active',
        'combinable_with',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
            'combinable_with' => 'array',
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
     * @return HasMany<ReservationTableAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ReservationTableAssignment::class);
    }
}
