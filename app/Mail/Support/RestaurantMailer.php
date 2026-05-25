<?php

declare(strict_types=1);

namespace App\Mail\Support;

use App\Models\Restaurant;

/**
 * Registers a runtime SMTP mailer for a restaurant that has its own SMTP
 * config (PRD-016 Phase 1b) and returns its name. Returns null when the
 * restaurant has no SMTP configured, so callers fall back to the default
 * `.env` mailer. The mailer config is added in-memory (config()) for the
 * current process only; it is never written to a config file.
 */
final class RestaurantMailer
{
    /**
     * @return string|null the runtime mailer name, or null to use the default
     */
    public function resolve(Restaurant $restaurant): ?string
    {
        if ($restaurant->smtp_host === null || $restaurant->smtp_host === '') {
            return null;
        }

        $name = 'restaurant-'.$restaurant->id;

        config([
            "mail.mailers.{$name}" => [
                'transport' => 'smtp',
                'host' => $restaurant->smtp_host,
                'port' => $restaurant->smtp_port ?? 587,
                'username' => $restaurant->smtp_username,
                'password' => $restaurant->smtp_password,
                'encryption' => 'tls',
                'timeout' => null,
            ],
        ]);

        return $name;
    }

    /**
     * The From address/name for the restaurant: its own when configured,
     * otherwise the global `mail.from`.
     *
     * @return array{address: string, name: string}
     */
    public function from(Restaurant $restaurant): array
    {
        return [
            'address' => $restaurant->smtp_from_address ?? (string) config('mail.from.address'),
            'name' => $restaurant->smtp_from_name ?? (string) config('mail.from.name'),
        ];
    }
}
