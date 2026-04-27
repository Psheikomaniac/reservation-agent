<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Restaurant;
use Database\Seeders\PerformanceBenchmarkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PerformanceBenchmarkSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_target_count_matches_issue_61_acceptance_criterion(): void
    {
        $this->assertSame(10_000, PerformanceBenchmarkSeeder::RESERVATION_COUNT);
        $this->assertSame(5, PerformanceBenchmarkSeeder::RESTAURANT_COUNT);
    }

    public function test_seeder_creates_expected_restaurants_and_reservations(): void
    {
        $this->runWithCount(120);

        $this->assertSame(
            PerformanceBenchmarkSeeder::RESTAURANT_COUNT,
            Restaurant::query()->where('slug', 'like', 'benchmark-restaurant-%')->count(),
        );

        $this->assertSame(120, DB::table('reservation_requests')->count());
    }

    public function test_seeder_distributes_reservations_across_all_benchmark_restaurants(): void
    {
        $this->runWithCount(120);

        $perRestaurant = DB::table('reservation_requests')
            ->selectRaw('restaurant_id, COUNT(*) as c')
            ->groupBy('restaurant_id')
            ->pluck('c', 'restaurant_id');

        $this->assertCount(PerformanceBenchmarkSeeder::RESTAURANT_COUNT, $perRestaurant);

        foreach ($perRestaurant as $count) {
            $this->assertGreaterThan(0, $count);
        }
    }

    public function test_seeder_is_idempotent_for_restaurant_creation(): void
    {
        $this->runWithCount(60);
        $this->runWithCount(60);

        $this->assertSame(
            PerformanceBenchmarkSeeder::RESTAURANT_COUNT,
            Restaurant::query()->where('slug', 'like', 'benchmark-restaurant-%')->count(),
        );

        $this->assertSame(120, DB::table('reservation_requests')->count());
    }

    private function runWithCount(int $count): void
    {
        $seeder = new PerformanceBenchmarkSeeder;
        $seeder->count = $count;
        $seeder->run();
    }
}
