<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Tonality;
use App\Enums\UserRole;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Carries the freshly created invitation together with its one-time plaintext
 * token, which only exists in memory at provision time.
 */
final readonly class ProvisionResult
{
    public function __construct(
        public Restaurant $restaurant,
        public User $owner,
        public Invitation $invitation,
        public string $plainToken,
    ) {}
}

/**
 * Creates a restaurant, its owner (password-less until the invitation is
 * accepted) and an owner invitation in one transaction. Reused by the
 * `restaurant:provision` command today and the Super-Admin UI later.
 */
final class RestaurantProvisioner
{
    private const INVITE_TTL_DAYS = 7;

    public function provision(string $name, string $slug, string $email, string $timezone = 'Europe/Berlin'): ProvisionResult
    {
        $this->validate($slug, $email, $timezone);

        return DB::transaction(function () use ($name, $slug, $email, $timezone): ProvisionResult {
            $restaurant = Restaurant::create([
                'name' => $name,
                'slug' => $slug,
                'timezone' => $timezone,
                // NOT NULL columns without DB defaults — seeded with safe
                // placeholders the wizard overwrites (PRD-016 §Provisionierung).
                'capacity' => 0,
                'opening_hours' => [],
                'tonality' => Tonality::Formal,
            ]);

            $owner = User::create([
                'name' => $this->placeholderName($email),
                'email' => $email,
                'password' => null,
                'restaurant_id' => $restaurant->id,
                'role' => UserRole::Owner,
            ]);

            $plainToken = Invitation::generateToken();

            $invitation = Invitation::create([
                'restaurant_id' => $restaurant->id,
                'email' => $email,
                'role' => UserRole::Owner,
                'token' => Invitation::hashToken($plainToken),
                'expires_at' => now()->addDays(self::INVITE_TTL_DAYS),
            ]);

            return new ProvisionResult($restaurant, $owner, $invitation, $plainToken);
        });
    }

    private function validate(string $slug, string $email, string $timezone): void
    {
        Validator::make(
            ['slug' => $slug, 'email' => $email, 'timezone' => $timezone],
            [
                'slug' => ['required', 'string', 'max:255', 'unique:restaurants,slug'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'timezone' => ['required', 'timezone'],
            ],
        )->validate();
    }

    private function placeholderName(string $email): string
    {
        return Str::of($email)->before('@')->headline()->value();
    }
}
