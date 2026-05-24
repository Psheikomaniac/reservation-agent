<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Scopes\RestaurantScope;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A tokenised invitation to join a restaurant as owner or staff.
 *
 * The plaintext token is shown exactly once (at provision/invite time) and
 * never stored — only its SHA-256 hash lives in the `token` column, so a
 * leaked database row cannot be replayed against the acceptance route.
 */
#[ScopedBy(RestaurantScope::class)]
final class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'restaurant_id',
        'email',
        'role',
        'token',
        'expires_at',
        'accepted_at',
    ];

    /**
     * Never serialize the token hash — invitations may later be surfaced in
     * Inertia props (team lists), and the hash has no place in the browser.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Look up an invitation by its plaintext token. Runs without the tenant
     * scope because acceptance happens before the guest is logged in.
     */
    public static function findByToken(string $plain): ?self
    {
        return self::query()
            ->withoutGlobalScopes()
            ->where('token', self::hashToken($plain))
            ->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isExpired() && ! $this->isAccepted();
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
