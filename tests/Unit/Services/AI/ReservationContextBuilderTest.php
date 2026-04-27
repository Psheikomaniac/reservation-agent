<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Enums\ReservationStatus;
use App\Enums\Tonality;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Services\AI\ReservationContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ReservationContextBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new ReservationContextBuilder;
    }

    public function test_it_returns_full_context_shape_for_an_open_time_window(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'La Trattoria',
            'capacity' => 40,
            'tonality' => Tonality::Casual,
            'timezone' => 'Europe/Berlin',
        ]);

        // Wednesday 19:30 local time → inside the dinner block (18:00-22:30).
        $desiredAt = Carbon::parse('2026-05-13 19:30', 'Europe/Berlin')->utc();

        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'guest_name' => 'Anna Müller',
            'party_size' => 4,
            'desired_at' => $desiredAt,
            'message' => 'Möglichst Fensterplatz, danke.',
        ]);

        $context = $this->builder->build($request);

        $this->assertSame('La Trattoria', $context['restaurant']['name']);
        $this->assertSame('casual', $context['restaurant']['tonality']);

        $this->assertSame('Anna Müller', $context['request']['guest_name']);
        $this->assertSame(4, $context['request']['party_size']);
        $this->assertSame('2026-05-13 19:30', $context['request']['desired_at']);
        $this->assertSame('Möglichst Fensterplatz, danke.', $context['request']['message']);

        $this->assertTrue($context['availability']['is_open_at_desired_time']);
        $this->assertSame(40, $context['availability']['seats_free_at_desired']);
        $this->assertSame([], $context['availability']['alternative_slots']);
        $this->assertNull($context['availability']['closed_reason']);
    }

    public function test_it_subtracts_confirmed_party_sizes_from_seats_free(): void
    {
        $restaurant = Restaurant::factory()->create([
            'capacity' => 40,
            'timezone' => 'Europe/Berlin',
        ]);

        $desiredAt = Carbon::parse('2026-05-13 19:30', 'Europe/Berlin')->utc();

        // A confirmed reservation 30min later inside the ±2h window blocks 6 seats.
        ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => ReservationStatus::Confirmed,
            'party_size' => 6,
            'desired_at' => $desiredAt->copy()->addMinutes(30),
        ]);

        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'party_size' => 2,
            'desired_at' => $desiredAt,
        ]);

        $context = $this->builder->build($request);

        $this->assertSame(34, $context['availability']['seats_free_at_desired']);
    }

    public function test_it_flags_outside_opening_hours_as_closed_reason(): void
    {
        $restaurant = Restaurant::factory()->create([
            'timezone' => 'Europe/Berlin',
        ]);

        // Wednesday 16:00 local — between lunch (until 14:30) and dinner (from 18:00).
        $desiredAt = Carbon::parse('2026-05-13 16:00', 'Europe/Berlin')->utc();

        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'desired_at' => $desiredAt,
        ]);

        $context = $this->builder->build($request);

        $this->assertFalse($context['availability']['is_open_at_desired_time']);
        $this->assertSame('ausserhalb_oeffnungszeiten', $context['availability']['closed_reason']);
        $this->assertSame(0, $context['availability']['seats_free_at_desired']);
    }

    public function test_it_flags_ruhetag_as_closed_reason(): void
    {
        $restaurant = Restaurant::factory()->create([
            'timezone' => 'Europe/Berlin',
        ]);

        // Tuesday is the Ruhetag (empty schedule in the factory).
        $desiredAt = Carbon::parse('2026-05-12 19:30', 'Europe/Berlin')->utc();

        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'desired_at' => $desiredAt,
        ]);

        $context = $this->builder->build($request);

        $this->assertFalse($context['availability']['is_open_at_desired_time']);
        $this->assertSame('ruhetag', $context['availability']['closed_reason']);
        $this->assertSame([], $context['availability']['alternative_slots']);
    }

    public function test_desired_at_is_returned_in_restaurant_local_time_even_when_request_is_utc(): void
    {
        $restaurant = Restaurant::factory()->create([
            'timezone' => 'Europe/Berlin',
        ]);

        // 17:30 UTC == 19:30 Europe/Berlin (CEST, summer offset).
        $desiredAtUtc = Carbon::parse('2026-05-13 17:30', 'UTC');

        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'desired_at' => $desiredAtUtc,
        ]);

        $context = $this->builder->build($request);

        $this->assertSame('2026-05-13 19:30', $context['request']['desired_at']);
    }
}
