<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BenchmarkDashboardCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_against_seeded_restaurant_and_emits_explain_output(): void
    {
        $restaurant = Restaurant::factory()->create();

        ReservationRequest::factory()
            ->forRestaurant($restaurant)
            ->count(5)
            ->state(['status' => ReservationStatus::New, 'desired_at' => now()->addDay()])
            ->create();

        $this->artisan('dashboard:benchmark', [
            '--restaurant' => $restaurant->id,
            '--iterations' => 3,
            '--warmup' => 0,
        ])
            ->expectsOutputToContain('Iterations: 3')
            ->expectsOutputToContain('p95')
            ->expectsOutputToContain('EXPLAIN')
            ->assertSuccessful();
    }

    public function test_command_fails_gracefully_without_data(): void
    {
        $this->artisan('dashboard:benchmark')
            ->expectsOutputToContain('No restaurant available')
            ->assertFailed();
    }
}
