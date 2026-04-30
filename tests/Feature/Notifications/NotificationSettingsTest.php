<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_returns_the_full_prd010_shape(): void
    {
        $defaults = NotificationSettings::default();

        $this->assertSame([
            'browser_notifications' => false,
            'sound_alerts' => false,
            'sound' => 'default',
            'volume' => 70,
            'daily_digest' => true,
            'daily_digest_at' => '18:00',
        ], $defaults);
    }

    public function test_merge_layers_user_values_over_defaults(): void
    {
        $merged = NotificationSettings::merge([
            'sound_alerts' => true,
            'volume' => 50,
        ]);

        $this->assertSame([
            'browser_notifications' => false,
            'sound_alerts' => true,
            'sound' => 'default',
            'volume' => 50,
            'daily_digest' => true,
            'daily_digest_at' => '18:00',
        ], $merged);
    }

    public function test_merge_keeps_unknown_stored_keys_intact(): void
    {
        $merged = NotificationSettings::merge([
            'legacy_key' => 'legacy_value',
        ]);

        $this->assertArrayHasKey('legacy_key', $merged);
        $this->assertSame('legacy_value', $merged['legacy_key']);
    }

    public function test_user_accessor_returns_defaults_for_a_fresh_user(): void
    {
        $user = User::factory()
            ->forRestaurant(Restaurant::factory()->create())
            ->create();

        $this->assertSame(
            NotificationSettings::default(),
            $user->notification_settings,
        );
    }

    public function test_user_accessor_merges_defaults_for_missing_keys(): void
    {
        $user = User::factory()
            ->forRestaurant(Restaurant::factory()->create())
            ->create([
                'notification_settings' => ['sound_alerts' => true],
            ]);
        $user->refresh();

        $settings = $user->notification_settings;

        $this->assertTrue($settings['sound_alerts']);
        $this->assertFalse($settings['browser_notifications']);
        $this->assertSame(70, $settings['volume']);
        $this->assertSame('18:00', $settings['daily_digest_at']);
    }

    public function test_user_accessor_persists_user_set_values_round_trip(): void
    {
        $user = User::factory()
            ->forRestaurant(Restaurant::factory()->create())
            ->create();

        $user->notification_settings = [
            'browser_notifications' => true,
            'sound_alerts' => true,
            'volume' => 30,
        ];
        $user->save();

        $reloaded = User::query()->find($user->id);

        $this->assertTrue($reloaded->notification_settings['browser_notifications']);
        $this->assertTrue($reloaded->notification_settings['sound_alerts']);
        $this->assertSame(30, $reloaded->notification_settings['volume']);
        // Untouched defaults survive the round-trip.
        $this->assertTrue($reloaded->notification_settings['daily_digest']);
        $this->assertSame('18:00', $reloaded->notification_settings['daily_digest_at']);
    }
}
