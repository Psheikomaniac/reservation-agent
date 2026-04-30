<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\SendDailyDigestJob;
use App\Mail\DailyDigestMail;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DailyDigestJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function userWithRestaurant(string $timezone = 'Europe/Berlin', string $sendAt = '18:00', bool $digestEnabled = true): User
    {
        $restaurant = Restaurant::factory()->create(['timezone' => $timezone]);

        return User::factory()->forRestaurant($restaurant)->create([
            'notification_settings' => [
                'daily_digest' => $digestEnabled,
                'daily_digest_at' => $sendAt,
            ],
        ]);
    }

    public function test_sends_digest_at_user_configured_time_in_restaurant_timezone(): void
    {
        $user = $this->userWithRestaurant('Europe/Berlin', '18:00');

        // 2026-04-30 18:00 Berlin = 16:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-04-30 16:00:00', 'UTC'));

        (new SendDailyDigestJob)->handle();

        Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $mail) => $mail->hasTo($user->email));

        Carbon::setTestNow();
    }

    public function test_does_not_send_when_clock_misses_configured_minute(): void
    {
        $this->userWithRestaurant('Europe/Berlin', '18:00');

        Carbon::setTestNow(Carbon::parse('2026-04-30 17:30:00', 'UTC')); // 19:30 Berlin

        (new SendDailyDigestJob)->handle();

        Mail::assertNothingSent();

        Carbon::setTestNow();
    }

    public function test_does_not_send_digest_when_setting_is_off(): void
    {
        $this->userWithRestaurant('Europe/Berlin', '18:00', digestEnabled: false);

        Carbon::setTestNow(Carbon::parse('2026-04-30 16:00:00', 'UTC')); // 18:00 Berlin

        (new SendDailyDigestJob)->handle();

        Mail::assertNothingSent();

        Carbon::setTestNow();
    }

    public function test_skips_users_without_restaurant_id(): void
    {
        User::factory()->create([
            'restaurant_id' => null,
            'notification_settings' => ['daily_digest' => true, 'daily_digest_at' => '18:00'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-30 16:00:00', 'UTC'));

        (new SendDailyDigestJob)->handle();

        Mail::assertNothingSent();

        Carbon::setTestNow();
    }

    public function test_sends_digest_only_once_per_user_per_day(): void
    {
        $user = $this->userWithRestaurant('Europe/Berlin', '18:00');

        Carbon::setTestNow(Carbon::parse('2026-04-30 16:00:00', 'UTC'));

        (new SendDailyDigestJob)->handle();
        // Same minute, second invocation — must not double-send.
        (new SendDailyDigestJob)->handle();

        Mail::assertSent(DailyDigestMail::class, 1);

        // Sanity-check the lock key is what subsequent debugging will look for.
        $expectedKey = sprintf('digest-sent:%d:%s', $user->id, '2026-04-30');
        $this->assertTrue(Cache::has($expectedKey));

        Carbon::setTestNow();
    }

    public function test_runs_independently_for_users_in_different_timezones(): void
    {
        $berlin = $this->userWithRestaurant('Europe/Berlin', '18:00');
        $newYork = $this->userWithRestaurant('America/New_York', '18:00');

        // 16:00 UTC == 18:00 Berlin (CEST), == 12:00 New York → only Berlin matches.
        Carbon::setTestNow(Carbon::parse('2026-04-30 16:00:00', 'UTC'));
        (new SendDailyDigestJob)->handle();

        Mail::assertSent(DailyDigestMail::class, 1);
        Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $mail) => $mail->hasTo($berlin->email));

        // Six hours later → 22:00 UTC == 18:00 New York → New York digest fires.
        Carbon::setTestNow(Carbon::parse('2026-04-30 22:00:00', 'UTC'));
        (new SendDailyDigestJob)->handle();

        Mail::assertSent(DailyDigestMail::class, 2);
        Mail::assertSent(DailyDigestMail::class, fn (DailyDigestMail $mail) => $mail->hasTo($newYork->email));

        Carbon::setTestNow();
    }
}
