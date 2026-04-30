<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ReservationStatus;
use App\Mail\DailyDigestMail;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Notifications\DigestSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyDigestMailTest extends TestCase
{
    use RefreshDatabase;

    private function userInBerlin(string $name = 'Trattoria Testa'): User
    {
        return User::factory()
            ->forRestaurant(Restaurant::factory()->create([
                'name' => $name,
                'timezone' => 'Europe/Berlin',
            ]))
            ->create();
    }

    public function test_subject_carries_the_restaurant_name(): void
    {
        $user = $this->userInBerlin('Bistro am Hafen');
        $summary = DigestSummary::forUser($user, 'https://example.test/dashboard');

        $mail = new DailyDigestMail($summary);

        $this->assertSame('Tageszusammenfassung — Bistro am Hafen', $mail->envelope()->subject);
    }

    public function test_summary_contains_correct_counts_for_today(): void
    {
        $user = $this->userInBerlin();
        $restaurant = $user->restaurant;

        // Anchor "today" so the test is stable regardless of the
        // server clock. 2026-04-30 09:00 Europe/Berlin → 07:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:00:00', 'Europe/Berlin'));

        // 4 today: 1 confirmed, 1 in_review, 1 new (also needs_manual_review),
        // 1 declined (counts toward total but not pending/confirmed/needs_review).
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'created_at' => Carbon::now('Europe/Berlin'),
            'status' => ReservationStatus::Confirmed,
            'needs_manual_review' => false,
        ]);
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'created_at' => Carbon::now('Europe/Berlin'),
            'status' => ReservationStatus::InReview,
            'needs_manual_review' => false,
        ]);
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'created_at' => Carbon::now('Europe/Berlin'),
            'status' => ReservationStatus::New,
            'needs_manual_review' => true,
        ]);
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'created_at' => Carbon::now('Europe/Berlin'),
            'status' => ReservationStatus::Declined,
            'needs_manual_review' => false,
        ]);
        // A request from yesterday must NOT count.
        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'created_at' => Carbon::now('Europe/Berlin')->subDay()->setTime(20, 0),
            'status' => ReservationStatus::New,
            'needs_manual_review' => false,
        ]);

        $summary = DigestSummary::forUser($user, 'https://example.test/dashboard');

        $this->assertSame(4, $summary->totalToday);
        $this->assertSame(1, $summary->confirmed);
        $this->assertSame(2, $summary->pending);
        $this->assertSame(1, $summary->needsReview);

        Carbon::setTestNow();
    }

    public function test_summary_contains_only_own_restaurant_counts(): void
    {
        $own = $this->userInBerlin('Eigenes Lokal');
        $foreign = Restaurant::factory()->create(['name' => 'Fremd']);

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:00:00', 'Europe/Berlin'));

        ReservationRequest::factory()->forRestaurant($own->restaurant)->create([
            'created_at' => Carbon::now('Europe/Berlin'),
            'status' => ReservationStatus::Confirmed,
        ]);
        ReservationRequest::factory()->forRestaurant($foreign)->count(5)->create([
            'created_at' => Carbon::now('Europe/Berlin'),
            'status' => ReservationStatus::Confirmed,
        ]);

        $summary = DigestSummary::forUser($own, 'https://example.test/dashboard');

        $this->assertSame(1, $summary->totalToday);
        $this->assertSame(1, $summary->confirmed);

        Carbon::setTestNow();
    }

    public function test_summary_uses_restaurant_timezone_for_today_window(): void
    {
        $user = $this->userInBerlin();
        $restaurant = $user->restaurant;

        // "Now" sits at 2026-04-30 23:30 Berlin (= 21:30 UTC), already
        // past the UTC midnight rollover. A reservation created at
        // 2026-04-30 22:00 Berlin (20:00 UTC) belongs to today's
        // local digest, not yesterday's.
        Carbon::setTestNow(Carbon::parse('2026-04-30 23:30:00', 'Europe/Berlin'));

        ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'created_at' => Carbon::parse('2026-04-30 22:00:00', 'Europe/Berlin'),
            'status' => ReservationStatus::New,
        ]);

        $summary = DigestSummary::forUser($user, 'https://example.test/dashboard');
        $this->assertSame(1, $summary->totalToday);

        Carbon::setTestNow();
    }

    public function test_view_renders_dashboard_url_and_counts(): void
    {
        $user = $this->userInBerlin('Pizzeria Giallo');
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:00:00', 'Europe/Berlin'));

        $summary = new DigestSummary(
            restaurantName: 'Pizzeria Giallo',
            totalToday: 7,
            confirmed: 3,
            pending: 2,
            needsReview: 1,
            dashboardUrl: 'https://example.test/dashboard',
        );

        $rendered = (new DailyDigestMail($summary))->render();

        $this->assertStringContainsString('Pizzeria Giallo', $rendered);
        $this->assertStringContainsString('https://example.test/dashboard', $rendered);
        // The four numbers must appear near their labels — assert
        // they're present so a future template change can't silently
        // drop a metric.
        $this->assertStringContainsString('>7<', $rendered);
        $this->assertStringContainsString('>3<', $rendered);
        $this->assertStringContainsString('>2<', $rendered);
        $this->assertStringContainsString('>1<', $rendered);
        // Settings link must be present so the operator can opt out.
        $this->assertStringContainsString(route('settings.notifications.edit'), $rendered);

        Carbon::setTestNow();
        // Suppress test passthroughs.
        unset($user);
    }
}
