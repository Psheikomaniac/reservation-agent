<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Services\Notifications\NotificationSettings;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'restaurant_id',
        'role',
        'notification_settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            // The custom accessor / mutator below already handles
            // JSON encoding + the merge step. The `array` entry is
            // kept per PRD-010 AC and documents the column shape;
            // Laravel skips it when an explicit Attribute is
            // defined for the same column.
            'notification_settings' => 'array',
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
     * Always return the merged shape for the PRD-010 settings.
     * Stored values override the defaults; missing keys (new
     * features, fresh users) read the defaults service. Writes
     * are JSON-encoded and persist whatever the caller assigns
     * — typically only the keys the operator actually toggled —
     * so the JSON column stays sparse.
     *
     * The custom accessor / mutator replaces the `array` cast
     * because the cast would skip the merge step on read and
     * surface fresh users as `[]` to the dashboard.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function notificationSettings(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                $stored = $value === null || $value === '' ? [] : (array) json_decode($value, true);

                return NotificationSettings::merge($stored);
            },
            set: fn (array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }
}
