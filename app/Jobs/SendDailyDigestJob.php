<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\DailyDigestMail;
use App\Models\User;
use App\Services\Notifications\DigestSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * PRD-010 § Email-Digest. Hourly fan-out job.
 *
 * The scheduler ticks once per hour (registered in `routes/console.php`,
 * sister issue #251). Each tick inspects every user that opted into the
 * daily digest and dispatches a `DailyDigestMail` to those whose
 * configured `daily_digest_at` matches the current hour:minute in their
 * restaurant's timezone.
 *
 * Hourly + per-user filter is the simplest robust approach for a
 * multi-timezone deployment: a single cron line covers the world; the
 * per-user clock comparison handles the offset. At thousands of
 * restaurants this would warrant a per-timezone cron (PRD-010 § Risiken).
 *
 * Idempotency is enforced by a cache lock keyed `digest-sent:{user_id}:{YYYY-MM-DD}`
 * with a 23-hour TTL — short enough to release before tomorrow's window,
 * long enough that a job re-run within the same minute cannot send twice.
 * Cache::add() is the atomic primitive (returns false if the key already
 * exists), which beats the read-then-write pattern under concurrent workers.
 */
final class SendDailyDigestJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $users = User::query()
            ->whereNotNull('restaurant_id')
            ->with('restaurant')
            ->get()
            ->filter(static fn (User $user): bool => ($user->notification_settings['daily_digest'] ?? false) === true);

        foreach ($users as $user) {
            if (! $this->shouldSendNow($user)) {
                continue;
            }

            $today = Carbon::now($user->restaurant->timezone)->toDateString();
            $lockKey = sprintf('digest-sent:%d:%s', $user->id, $today);

            // 23-hour TTL: long enough to deduplicate a re-run, short
            // enough that the lock has expired before tomorrow's
            // window opens. Cache::add() is the atomic check-and-set;
            // a parallel worker that races us simply gets `false` and
            // skips.
            if (! Cache::add($lockKey, true, now()->addHours(23))) {
                continue;
            }

            $summary = DigestSummary::forUser($user, route('dashboard'));
            Mail::to($user)->send(new DailyDigestMail($summary));
        }
    }

    private function shouldSendNow(User $user): bool
    {
        $configured = $user->notification_settings['daily_digest_at'] ?? '18:00';
        $localNow = Carbon::now($user->restaurant->timezone)->format('H:i');

        return $localNow === $configured;
    }
}
