<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Enums\SendMode;
use App\Enums\Tonality;
use App\Support\OpeningHours;
use Carbon\CarbonInterface;
use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Restaurant extends Model
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    /**
     * Symmetric window (in hours) around a query time used to sum
     * already-confirmed party sizes. Per PRD-005.
     */
    private const int AVAILABILITY_WINDOW_HOURS = 2;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'capacity',
        'opening_hours',
        'tonality',
        'onboarding_completed_at',
        'openai_api_key',
        'imap_host',
        'imap_username',
        'imap_password',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_from_address',
        'smtp_from_name',
        'send_mode',
        'auto_send_party_size_max',
        'auto_send_min_lead_time_minutes',
        'send_mode_changed_at',
        'send_mode_changed_by',
        'slot_buffer_minutes',
        'web_sync_confirm_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'openai_api_key',
        'imap_password',
        'smtp_password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'opening_hours' => 'array',
            'tonality' => Tonality::class,
            'onboarding_completed_at' => 'datetime',
            'openai_api_key' => 'encrypted',
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'smtp_port' => 'integer',
            'send_mode' => SendMode::class,
            'auto_send_party_size_max' => 'integer',
            'auto_send_min_lead_time_minutes' => 'integer',
            'send_mode_changed_at' => 'datetime',
            'slot_buffer_minutes' => 'integer',
            'web_sync_confirm_enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ReservationRequest, $this>
     */
    public function reservationRequests(): HasMany
    {
        return $this->hasMany(ReservationRequest::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * A restaurant is "live" once the onboarding Pflicht-Kern is complete.
     */
    public function isLive(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /**
     * @return HasMany<Table, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    /**
     * Audit trail of CSV/PDF exports run for this restaurant
     * (PRD-009). Append-only and tenant-scoped; cleanup of the
     * generated files is handled by `PurgeExpiredExportsJob`,
     * the audit rows themselves are retained.
     *
     * @return HasMany<ExportAudit, $this>
     */
    public function exportAudits(): HasMany
    {
        return $this->hasMany(ExportAudit::class);
    }

    /**
     * Free seats at a given point in time, or null if the restaurant is
     * closed then.
     *
     * `null` = closed (outside opening hours or Ruhetag).
     * `0`    = open but fully booked.
     * `> 0`  = open with remaining capacity.
     *
     * Calculation (per PRD-005): capacity minus the sum of `party_size`
     * of all **confirmed** reservations whose `desired_at` falls within
     * a ±2h window around the query time.
     */
    public function availableSeatsAt(CarbonInterface $time): ?int
    {
        if (! OpeningHours::fromRestaurant($this)->isOpenAt($time)) {
            return null;
        }

        $from = $time->copy()->subHours(self::AVAILABILITY_WINDOW_HOURS);
        $to = $time->copy()->addHours(self::AVAILABILITY_WINDOW_HOURS);

        $taken = (int) $this->reservationRequests()
            ->where('status', ReservationStatus::Confirmed)
            ->whereBetween('desired_at', [$from, $to])
            ->sum('party_size');

        return max(0, $this->capacity - $taken);
    }
}
