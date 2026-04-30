<?php

declare(strict_types=1);

namespace Tests\Unit\Analytics;

use App\Enums\AnalyticsRange;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\Analytics\AnalyticsAggregator;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AnalyticsAggregatorCachingTest extends TestCase
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
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Bind a Mockery spy as the CacheRepository so the aggregator
     * receives it via constructor injection. The spy invokes the
     * remember-callback inline so we get back a real
     * `AnalyticsSnapshot` to assert against, while still recording
     * the cache call arguments.
     */
    private function bindCacheSpy(): MockInterface
    {
        $spy = Mockery::spy(CacheRepository::class);
        $spy->shouldReceive('remember')
            ->andReturnUsing(fn (string $key, $ttl, callable $callback) => $callback());

        $this->app->instance(CacheRepository::class, $spy);

        return $spy;
    }

    public function test_it_caches_aggregator_results_for_5_minutes(): void
    {
        $cache = $this->bindCacheSpy();
        $restaurant = Restaurant::factory()->create();

        $aggregator = $this->app->make(AnalyticsAggregator::class);
        $aggregator->aggregate($restaurant, AnalyticsRange::Last7Days);

        $expectedKey = 'analytics:'.$restaurant->id.':7d';

        $cache->shouldHaveReceived('remember')
            ->once()
            ->with(
                $expectedKey,
                Mockery::on(static function ($ttl): bool {
                    // Five minutes from "now" (2026-04-30 12:00:00 UTC)
                    // → 2026-04-30 12:05:00. Accept either a Carbon
                    // instance or a DateTime as long as it lands on
                    // that minute.
                    return $ttl instanceof \DateTimeInterface
                        && $ttl->format('Y-m-d H:i') === '2026-04-30 12:05';
                }),
                Mockery::type('callable'),
            );
        $this->addToAssertionCount(1);
    }

    public function test_it_uses_different_cache_keys_per_restaurant(): void
    {
        $cache = $this->bindCacheSpy();
        $first = Restaurant::factory()->create();
        $second = Restaurant::factory()->create();

        $aggregator = $this->app->make(AnalyticsAggregator::class);
        $aggregator->aggregate($first, AnalyticsRange::Last7Days);
        $aggregator->aggregate($second, AnalyticsRange::Last7Days);

        $cache->shouldHaveReceived('remember')
            ->with('analytics:'.$first->id.':7d', Mockery::any(), Mockery::any());
        $cache->shouldHaveReceived('remember')
            ->with('analytics:'.$second->id.':7d', Mockery::any(), Mockery::any());
        $this->addToAssertionCount(2);
    }

    public function test_it_uses_different_cache_keys_per_range(): void
    {
        $cache = $this->bindCacheSpy();
        $restaurant = Restaurant::factory()->create();

        $aggregator = $this->app->make(AnalyticsAggregator::class);
        $aggregator->aggregate($restaurant, AnalyticsRange::Today);
        $aggregator->aggregate($restaurant, AnalyticsRange::Last7Days);
        $aggregator->aggregate($restaurant, AnalyticsRange::Last30Days);

        foreach (['today', '7d', '30d'] as $rangeValue) {
            $cache->shouldHaveReceived('remember')
                ->with(
                    'analytics:'.$restaurant->id.':'.$rangeValue,
                    Mockery::any(),
                    Mockery::any(),
                );
            $this->addToAssertionCount(1);
        }
    }

    public function test_repeated_calls_reach_the_cache_store_only_once_per_key(): void
    {
        // The default binding (array driver) — no Mockery spy this
        // time. The point is to verify behaviour end-to-end: a
        // second call with identical (restaurant, range) does NOT
        // re-execute the aggregation closure.
        $restaurant = Restaurant::factory()->create();
        $aggregator = $this->app->make(AnalyticsAggregator::class);

        $first = $aggregator->aggregate($restaurant, AnalyticsRange::Last7Days);

        // Mutating data after the first call must not change the
        // cached snapshot — proves the second call hit the cache.
        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->create(['created_at' => Carbon::now()->subDays(1)]);

        $second = $aggregator->aggregate($restaurant, AnalyticsRange::Last7Days);

        $this->assertSame($first->totals['total'], $second->totals['total']);

        // A different range key must bypass the cache.
        $thirdRange = $aggregator->aggregate($restaurant, AnalyticsRange::Last30Days);
        $this->assertSame(1, $thirdRange->totals['total']);
    }
}
