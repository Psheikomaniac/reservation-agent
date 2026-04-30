<?php

declare(strict_types=1);

namespace App\Services\Notifications;

/**
 * Stateless defaults + merge service for the PRD-010
 * notification preferences. Stored as a JSON column on the
 * `users` table; the schema is intentionally JSON (not a
 * separate `notification_settings` table) because the keys
 * grow with every PRD-010 sibling issue and we don't want a
 * migration per key.
 *
 * `merge()` lets new defaults appear for every existing user
 * without backfilling — they read the merged shape, write back
 * only what they actually toggle. Database stays sparse.
 */
final class NotificationSettings
{
    /**
     * @return array{
     *     browser_notifications: bool,
     *     sound_alerts: bool,
     *     sound: string,
     *     volume: int,
     *     daily_digest: bool,
     *     daily_digest_at: string,
     * }
     */
    public static function default(): array
    {
        return [
            // Browser-Push (PRD-010 issue #244 / #245). Off by
            // default — the operator must explicitly grant
            // permission via the settings UI.
            'browser_notifications' => false,

            // Sound alert when a new request lands. Off by
            // default for the same reason; volume + sound choice
            // surface in the UI once `sound_alerts` is true.
            'sound_alerts' => false,
            'sound' => 'default',
            'volume' => 70,

            // Evening digest — on by default. PRD-010 expects
            // most owners to want a 18:00 wrap-up of "what
            // happened today" without subscribing to push first.
            'daily_digest' => true,
            'daily_digest_at' => '18:00',
        ];
    }

    /**
     * Merge user-stored values over the defaults. Unknown keys
     * in `$stored` (e.g. left over from a removed feature) are
     * preserved so we never silently drop user data; consumers
     * iterate the returned shape via the typed accessor.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function merge(array $stored): array
    {
        return [...self::default(), ...$stored];
    }
}
