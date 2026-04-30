<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRestaurant(): User
    {
        return User::factory()
            ->forRestaurant(Restaurant::factory()->create())
            ->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'browser_notifications' => true,
            'sound_alerts' => true,
            'sound' => 'chime',
            'volume' => 60,
            'daily_digest' => true,
            'daily_digest_at' => '09:30',
        ], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->put(route('settings.notifications.update'), $this->payload())
            ->assertRedirect('/login');
    }

    public function test_it_persists_notification_settings(): void
    {
        $user = $this->userWithRestaurant();

        $this->actingAs($user)
            ->from(route('settings.notifications.edit'))
            ->put(route('settings.notifications.update'), $this->payload([
                'browser_notifications' => true,
                'sound_alerts' => true,
                'sound' => 'tap',
                'volume' => 45,
                'daily_digest' => false,
                'daily_digest_at' => '20:00',
            ]))
            ->assertRedirect()
            ->assertSessionHas('status', 'notification-settings-updated');

        $stored = User::query()->find($user->id)->notification_settings;
        $this->assertTrue($stored['browser_notifications']);
        $this->assertTrue($stored['sound_alerts']);
        $this->assertSame('tap', $stored['sound']);
        $this->assertSame(45, $stored['volume']);
        $this->assertFalse($stored['daily_digest']);
        $this->assertSame('20:00', $stored['daily_digest_at']);
    }

    public function test_it_merges_defaults_for_missing_keys_in_storage(): void
    {
        $user = $this->userWithRestaurant();
        $user->forceFill([
            'notification_settings' => ['sound_alerts' => true],
        ])->save();
        $user->refresh();

        // The accessor backfills the missing keys from defaults.
        $this->assertTrue($user->notification_settings['sound_alerts']);
        $this->assertFalse($user->notification_settings['browser_notifications']);
        $this->assertSame(70, $user->notification_settings['volume']);
        $this->assertSame('18:00', $user->notification_settings['daily_digest_at']);
    }

    public function test_settings_page_renders_defaults_for_a_fresh_user(): void
    {
        $user = $this->userWithRestaurant();

        $this->actingAs($user)
            ->get(route('settings.notifications.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Notifications')
                ->where('settings.browser_notifications', false)
                ->where('settings.sound_alerts', false)
                ->where('settings.daily_digest', true)
                ->where('settings.daily_digest_at', '18:00')
                ->etc()
            );
    }

    public function test_validation_rejects_volume_above_100(): void
    {
        $user = $this->userWithRestaurant();

        $this->actingAs($user)
            ->from(route('settings.notifications.edit'))
            ->put(route('settings.notifications.update'), $this->payload(['volume' => 250]))
            ->assertSessionHasErrors('volume');
    }

    public function test_validation_rejects_negative_volume(): void
    {
        $user = $this->userWithRestaurant();

        $this->actingAs($user)
            ->from(route('settings.notifications.edit'))
            ->put(route('settings.notifications.update'), $this->payload(['volume' => -5]))
            ->assertSessionHasErrors('volume');
    }

    public function test_validation_rejects_unknown_sound_choice(): void
    {
        $user = $this->userWithRestaurant();

        $this->actingAs($user)
            ->from(route('settings.notifications.edit'))
            ->put(route('settings.notifications.update'), $this->payload(['sound' => 'siren']))
            ->assertSessionHasErrors('sound');
    }

    public function test_validation_rejects_invalid_time_format(): void
    {
        $user = $this->userWithRestaurant();

        $this->actingAs($user)
            ->from(route('settings.notifications.edit'))
            ->put(route('settings.notifications.update'), $this->payload(['daily_digest_at' => '7am']))
            ->assertSessionHasErrors('daily_digest_at');
    }

    public function test_validation_rejects_25_00_as_daily_digest_at(): void
    {
        $user = $this->userWithRestaurant();

        $this->actingAs($user)
            ->from(route('settings.notifications.edit'))
            ->put(route('settings.notifications.update'), $this->payload(['daily_digest_at' => '25:00']))
            ->assertSessionHasErrors('daily_digest_at');
    }

    public function test_authenticated_user_only_modifies_their_own_settings(): void
    {
        $own = $this->userWithRestaurant();
        $other = $this->userWithRestaurant();

        $this->actingAs($own)
            ->put(route('settings.notifications.update'), $this->payload([
                'browser_notifications' => true,
            ]));

        $own->refresh();
        $other->refresh();

        $this->assertTrue($own->notification_settings['browser_notifications']);
        // Sibling user's settings stay at defaults — false — even
        // though the request body had no user id at all (the
        // controller only ever updates auth()->user()).
        $this->assertFalse($other->notification_settings['browser_notifications']);
    }
}
