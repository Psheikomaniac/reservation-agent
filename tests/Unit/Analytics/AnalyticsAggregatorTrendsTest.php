<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Enums\AnalyticsRange;
use App\Enums\MessageDirection;
use App\Enums\ReservationStatus;
use App\Models\ReservationMessage;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Analytics\AnalyticsAggregator;
use App\Services\Analytics\TrendBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsAggregatorTrendsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Anchor "now" so the day labels in assertions are stable.
        Carbon::setTestNow('2026-04-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function aggregator(): AnalyticsAggregator
    {
        return $this->app->make(AnalyticsAggregator::class);
    }

    private function makeRequest(
        Restaurant $restaurant,
        Carbon $createdAt,
        ReservationStatus $status = ReservationStatus::New,
    ): ReservationRequest {
        return ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create([
                'created_at' => $createdAt,
                'status' => $status,
            ]);
    }

    public function test_it_fills_trend_buckets_even_for_days_with_zero_requests(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'UTC']);

        // Two requests on the same day (yesterday); the rest of the
        // 7-day window has no activity at all.
        $this->makeRequest($restaurant, Carbon::parse('2026-04-29 09:00:00'));
        $this->makeRequest($restaurant, Carbon::parse('2026-04-29 18:30:00'));

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        // Last7Days = 7 inclusive day buckets.
        $this->assertCount(7, $snapshot->trends);

        $byLabel = [];
        foreach ($snapshot->trends as $bucket) {
            $this->assertInstanceOf(TrendBucket::class, $bucket);
            $byLabel[$bucket->label] = $bucket->count;
        }

        // Window starts 6 days before today (2026-04-30) → 2026-04-24.
        $this->assertSame(0, $byLabel['2026-04-24']);
        $this->assertSame(0, $byLabel['2026-04-25']);
        $this->assertSame(0, $byLabel['2026-04-26']);
        $this->assertSame(0, $byLabel['2026-04-27']);
        $this->assertSame(0, $byLabel['2026-04-28']);
        $this->assertSame(2, $byLabel['2026-04-29']);
        $this->assertSame(0, $byLabel['2026-04-30']);
    }

    public function test_it_returns_hourly_buckets_for_today_range(): void
    {
        // Re-anchor "now" past the test data so the 14:xx rows fit
        // inside the today range (which caps at endsAt = now).
        Carbon::setTestNow('2026-04-30 23:00:00');

        $restaurant = Restaurant::factory()->create(['timezone' => 'UTC']);

        // Three requests at 08:xx, two at 14:xx — the rest of the day empty.
        $this->makeRequest($restaurant, Carbon::parse('2026-04-30 08:05:00'));
        $this->makeRequest($restaurant, Carbon::parse('2026-04-30 08:30:00'));
        $this->makeRequest($restaurant, Carbon::parse('2026-04-30 08:55:00'));
        $this->makeRequest($restaurant, Carbon::parse('2026-04-30 14:10:00'));
        $this->makeRequest($restaurant, Carbon::parse('2026-04-30 14:50:00'));

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Today);

        $this->assertCount(24, $snapshot->trends);

        $byLabel = [];
        foreach ($snapshot->trends as $bucket) {
            $byLabel[$bucket->label] = $bucket->count;
        }

        $this->assertSame(3, $byLabel['08:00']);
        $this->assertSame(2, $byLabel['14:00']);
        $this->assertSame(0, $byLabel['00:00']);
        $this->assertSame(0, $byLabel['23:00']);
    }

    public function test_it_returns_30_days_of_buckets_for_last30days_range(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'UTC']);

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last30Days);

        $this->assertCount(30, $snapshot->trends);

        // First bucket is 29 days before today, last bucket is today.
        $this->assertSame('2026-04-01', $snapshot->trends[0]->label);
        $this->assertSame('2026-04-30', $snapshot->trends[29]->label);

        // All zero — no requests created in the window.
        foreach ($snapshot->trends as $bucket) {
            $this->assertSame(0, $bucket->count);
        }
    }

    public function test_it_computes_confirmation_rate_per_day_as_integer_percentage(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'UTC']);

        // Day A (2026-04-29): 4 requests, 1 confirmed → 25 %.
        $this->makeRequest($restaurant, Carbon::parse('2026-04-29 09:00'), ReservationStatus::Confirmed);
        $this->makeRequest($restaurant, Carbon::parse('2026-04-29 10:00'), ReservationStatus::New);
        $this->makeRequest($restaurant, Carbon::parse('2026-04-29 11:00'), ReservationStatus::New);
        $this->makeRequest($restaurant, Carbon::parse('2026-04-29 12:00'), ReservationStatus::Declined);

        // Day B (2026-04-28): 2 requests, both confirmed → 100 %.
        $this->makeRequest($restaurant, Carbon::parse('2026-04-28 18:00'), ReservationStatus::Confirmed);
        $this->makeRequest($restaurant, Carbon::parse('2026-04-28 19:00'), ReservationStatus::Confirmed);

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertCount(7, $snapshot->confirmationRateTrend);

        $byLabel = [];
        foreach ($snapshot->confirmationRateTrend as $bucket) {
            $byLabel[$bucket->label] = $bucket->count;
        }

        $this->assertSame(25, $byLabel['2026-04-29']);
        $this->assertSame(100, $byLabel['2026-04-28']);
        // Day with zero requests: 0 % (frontend masks to "—").
        $this->assertSame(0, $byLabel['2026-04-24']);
    }

    public function test_it_counts_inbound_thread_replies_per_day(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'UTC']);

        $request = $this->makeRequest($restaurant, Carbon::parse('2026-04-25 08:00'));

        // 2 inbound replies on 2026-04-29, 1 outbound (must NOT count),
        // 1 inbound on 2026-04-28.
        ReservationMessage::factory()->create([
            'reservation_request_id' => $request->id,
            'direction' => MessageDirection::In,
            'created_at' => Carbon::parse('2026-04-29 09:30:00'),
            'received_at' => Carbon::parse('2026-04-29 09:30:00'),
        ]);
        ReservationMessage::factory()->create([
            'reservation_request_id' => $request->id,
            'direction' => MessageDirection::In,
            'created_at' => Carbon::parse('2026-04-29 14:00:00'),
            'received_at' => Carbon::parse('2026-04-29 14:00:00'),
        ]);
        ReservationMessage::factory()->create([
            'reservation_request_id' => $request->id,
            'direction' => MessageDirection::Out,
            'created_at' => Carbon::parse('2026-04-29 12:00:00'),
            'sent_at' => Carbon::parse('2026-04-29 12:00:00'),
        ]);
        ReservationMessage::factory()->create([
            'reservation_request_id' => $request->id,
            'direction' => MessageDirection::In,
            'created_at' => Carbon::parse('2026-04-28 11:00:00'),
            'received_at' => Carbon::parse('2026-04-28 11:00:00'),
        ]);

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertCount(7, $snapshot->threadRepliesTrend);

        $byLabel = [];
        foreach ($snapshot->threadRepliesTrend as $bucket) {
            $byLabel[$bucket->label] = $bucket->count;
        }

        $this->assertSame(2, $byLabel['2026-04-29']);
        $this->assertSame(1, $byLabel['2026-04-28']);
        $this->assertSame(0, $byLabel['2026-04-27']);
    }

    public function test_it_scopes_trends_to_the_given_restaurant(): void
    {
        $own = Restaurant::factory()->create(['timezone' => 'UTC']);
        $other = Restaurant::factory()->create(['timezone' => 'UTC']);

        $this->makeRequest($own, Carbon::parse('2026-04-29 09:00'));

        // Other restaurant: 5 requests on the same day — must not bleed in.
        for ($i = 0; $i < 5; $i++) {
            $this->makeRequest($other, Carbon::parse('2026-04-29 10:00'));
        }

        $snapshot = $this->aggregator()->aggregate($own, AnalyticsRange::Last7Days);

        $byLabel = [];
        foreach ($snapshot->trends as $bucket) {
            $byLabel[$bucket->label] = $bucket->count;
        }

        $this->assertSame(1, $byLabel['2026-04-29']);
    }
}
