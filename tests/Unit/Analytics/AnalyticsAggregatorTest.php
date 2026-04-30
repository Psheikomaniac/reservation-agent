<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Enums\AnalyticsRange;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Analytics\AnalyticsAggregator;
use App\Services\Analytics\AnalyticsSnapshot;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsAggregatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function makeRequest(Restaurant $restaurant, array $attrs = []): ReservationRequest
    {
        return ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create($attrs);
    }

    public function test_it_counts_total_requests_within_range(): void
    {
        $restaurant = Restaurant::factory()->create();

        // 3 in range (last 7 days)
        $this->makeRequest($restaurant, ['created_at' => Carbon::now()->subDays(1)]);
        $this->makeRequest($restaurant, ['created_at' => Carbon::now()->subDays(3)]);
        $this->makeRequest($restaurant, ['created_at' => Carbon::now()->subDays(5)]);

        // 1 outside range (older than 7 days)
        $this->makeRequest($restaurant, ['created_at' => Carbon::now()->subDays(20)]);

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertInstanceOf(AnalyticsSnapshot::class, $snapshot);
        $this->assertSame(3, $snapshot->totals['total']);
    }

    public function test_it_counts_requests_by_source(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->makeRequest($restaurant, [
            'source' => ReservationSource::WebForm,
            'created_at' => Carbon::now()->subDays(1),
        ]);
        $this->makeRequest($restaurant, [
            'source' => ReservationSource::WebForm,
            'created_at' => Carbon::now()->subDays(2),
        ]);
        $this->makeRequest($restaurant, [
            'source' => ReservationSource::Email,
            'created_at' => Carbon::now()->subDays(3),
        ]);

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertSame(2, $snapshot->sources['web_form']);
        $this->assertSame(1, $snapshot->sources['email']);
    }

    public function test_it_computes_confirmation_rate(): void
    {
        $restaurant = Restaurant::factory()->create();

        // 4 confirmed of 10 total → 40 % confirmation rate
        for ($i = 0; $i < 4; $i++) {
            $this->makeRequest($restaurant, [
                'status' => ReservationStatus::Confirmed,
                'created_at' => Carbon::now()->subDays(2),
            ]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->makeRequest($restaurant, [
                'status' => ReservationStatus::New,
                'created_at' => Carbon::now()->subDays(2),
            ]);
        }

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertSame(4, $snapshot->statusBreakdown['confirmed']);
        $this->assertSame(10, $snapshot->totals['total']);

        $rate = $snapshot->statusBreakdown['confirmed'] / $snapshot->totals['total'];
        $this->assertEqualsWithDelta(0.4, $rate, 0.0001);
    }

    public function test_it_computes_rejection_rate(): void
    {
        $restaurant = Restaurant::factory()->create();

        // 2 declined of 8 total → 25 % rejection rate
        for ($i = 0; $i < 2; $i++) {
            $this->makeRequest($restaurant, [
                'status' => ReservationStatus::Declined,
                'created_at' => Carbon::now()->subDays(1),
            ]);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->makeRequest($restaurant, [
                'status' => ReservationStatus::New,
                'created_at' => Carbon::now()->subDays(1),
            ]);
        }

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertSame(2, $snapshot->statusBreakdown['declined']);
        $this->assertSame(8, $snapshot->totals['total']);

        $rate = $snapshot->statusBreakdown['declined'] / $snapshot->totals['total'];
        $this->assertEqualsWithDelta(0.25, $rate, 0.0001);
    }

    public function test_it_scopes_all_queries_to_restaurant_id(): void
    {
        $own = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();

        // Own restaurant: 2 requests
        $this->makeRequest($own, [
            'source' => ReservationSource::WebForm,
            'status' => ReservationStatus::Confirmed,
            'created_at' => Carbon::now()->subDays(1),
        ]);
        $this->makeRequest($own, [
            'source' => ReservationSource::Email,
            'status' => ReservationStatus::Declined,
            'created_at' => Carbon::now()->subDays(2),
        ]);

        // Other restaurant: 5 requests of various shapes that must NOT leak in
        for ($i = 0; $i < 5; $i++) {
            $this->makeRequest($other, [
                'source' => ReservationSource::WebForm,
                'status' => ReservationStatus::Confirmed,
                'created_at' => Carbon::now()->subDays(1),
            ]);
        }

        $snapshot = $this->aggregator()->aggregate($own, AnalyticsRange::Last7Days);

        $this->assertSame(2, $snapshot->totals['total']);
        $this->assertSame(1, $snapshot->sources['web_form']);
        $this->assertSame(1, $snapshot->sources['email']);
        $this->assertSame(1, $snapshot->statusBreakdown['confirmed']);
        $this->assertSame(1, $snapshot->statusBreakdown['declined']);
    }

    public function test_it_caches_the_snapshot_per_restaurant_and_range(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->makeRequest($restaurant, ['created_at' => Carbon::now()->subDays(1)]);

        $first = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);
        $this->assertSame(1, $first->totals['total']);

        // Insert another row directly bypassing the scope. The cached
        // snapshot must NOT see it within the 5-minute window.
        $this->makeRequest($restaurant, ['created_at' => Carbon::now()->subDays(1)]);

        $cached = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last7Days);
        $this->assertSame(1, $cached->totals['total']);

        // Different range key → recomputes and includes both rows.
        $fresh = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Last30Days);
        $this->assertSame(2, $fresh->totals['total']);
    }

    public function test_skeleton_fields_are_filled_with_safe_defaults(): void
    {
        $restaurant = Restaurant::factory()->create();

        $snapshot = $this->aggregator()->aggregate($restaurant, AnalyticsRange::Today);

        $this->assertSame(0, $snapshot->responseTime->sampleSize);
        $this->assertNull($snapshot->responseTime->medianMinutes);
        $this->assertNull($snapshot->editRate);
        $this->assertNull($snapshot->sendModeStats);
        $this->assertSame([], $snapshot->trends);
    }

    public function test_constructor_uses_cache_repository_contract(): void
    {
        $cache = $this->app->make(CacheRepository::class);
        $aggregator = new AnalyticsAggregator($cache);

        $restaurant = Restaurant::factory()->create();
        $snapshot = $aggregator->aggregate($restaurant, AnalyticsRange::Today);

        $this->assertInstanceOf(AnalyticsSnapshot::class, $snapshot);
    }
}
