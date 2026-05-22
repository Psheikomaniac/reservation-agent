<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Enums\ReservationStatus;
use App\Enums\Tonality;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
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

        $this->builder = $this->app->make(ReservationContextBuilder::class);
    }

    public function test_it_returns_full_context_shape_for_an_open_available_window(): void
    {
        $restaurant = Restaurant::factory()->create([
            'name' => 'La Trattoria',
            'capacity' => 40,
            'tonality' => Tonality::Casual,
            'timezone' => 'Europe/Berlin',
        ]);
        Table::factory()->for($restaurant)->create(['seats' => 4]);

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
        $this->assertNull($context['availability']['closed_reason']);
        $this->assertSame('free', $context['availability']['slot_state']);
        $this->assertTrue($context['availability']['is_available']);
        $this->assertIsArray($context['availability']['alternative_slots']);
    }

    public function test_it_reports_full_when_no_table_can_seat_the_party(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        Table::factory()->for($restaurant)->create(['seats' => 2]);

        $desiredAt = Carbon::parse('2026-05-13 19:30', 'Europe/Berlin')->utc();
        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'party_size' => 6,
            'desired_at' => $desiredAt,
        ]);

        $context = $this->builder->build($request);

        $this->assertTrue($context['availability']['is_open_at_desired_time']);
        $this->assertSame('full', $context['availability']['slot_state']);
        $this->assertFalse($context['availability']['is_available']);
    }

    public function test_it_reports_tight_when_load_is_high_but_a_table_remains(): void
    {
        // Half-full case (CLAUDE.md: voller/halber/leerer Auslastung): 4 tables x 4
        // = 16 seats, three booked at the desired time leaves 25 % → tight, but a
        // table still fits the party so the slot stays available.
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        $tables = Table::factory()->for($restaurant)->count(4)->create(['seats' => 4]);
        $desiredAt = Carbon::parse('2026-05-13 19:30', 'Europe/Berlin')->utc();
        foreach ([0, 1, 2] as $i) {
            $booking = ReservationRequest::factory()->forRestaurant($restaurant)->create([
                'status' => ReservationStatus::Confirmed,
                'party_size' => 4,
                'desired_at' => $desiredAt,
            ]);
            ReservationTableAssignment::factory()
                ->for($booking, 'reservationRequest')
                ->for($tables[$i])
                ->create();
        }

        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create([
            'party_size' => 2,
            'desired_at' => $desiredAt,
        ]);

        $context = $this->builder->build($request);

        $this->assertSame('tight', $context['availability']['slot_state']);
        $this->assertTrue($context['availability']['is_available']);
    }

    public function test_it_flags_outside_opening_hours_as_closed_reason(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        Table::factory()->for($restaurant)->create(['seats' => 4]);

        // Wednesday 16:00 local — between lunch (until 14:30) and dinner (from 18:00).
        $desiredAt = Carbon::parse('2026-05-13 16:00', 'Europe/Berlin')->utc();
        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'desired_at' => $desiredAt,
        ]);

        $context = $this->builder->build($request);

        $this->assertFalse($context['availability']['is_open_at_desired_time']);
        $this->assertSame('ausserhalb_oeffnungszeiten', $context['availability']['closed_reason']);
        $this->assertSame('full', $context['availability']['slot_state']);
        $this->assertFalse($context['availability']['is_available']);
    }

    public function test_it_flags_ruhetag_as_closed_reason(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);
        Table::factory()->for($restaurant)->create(['seats' => 4]);

        // Tuesday is the Ruhetag (empty schedule in the factory).
        $desiredAt = Carbon::parse('2026-05-12 19:30', 'Europe/Berlin')->utc();
        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'desired_at' => $desiredAt,
        ]);

        $context = $this->builder->build($request);

        $this->assertFalse($context['availability']['is_open_at_desired_time']);
        $this->assertSame('ruhetag', $context['availability']['closed_reason']);
        $this->assertSame('full', $context['availability']['slot_state']);
        $this->assertSame([], $context['availability']['alternative_slots']);
    }

    public function test_it_offers_alternative_slots_in_local_time_when_the_desired_slot_is_full(): void
    {
        // Zero buffer keeps the example tight: a 19:30 booking frees later slots
        // the same evening so the builder has alternatives to map.
        $restaurant = Restaurant::factory()->create([
            'timezone' => 'Europe/Berlin',
            'slot_buffer_minutes' => 0,
        ]);
        $table = Table::factory()->for($restaurant)->create(['seats' => 4]);

        $desiredAt = Carbon::parse('2026-05-13 19:30', 'Europe/Berlin')->utc();
        $booking = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'status' => ReservationStatus::Confirmed,
            'party_size' => 4,
            'desired_at' => $desiredAt,
        ]);
        ReservationTableAssignment::factory()
            ->for($booking, 'reservationRequest')
            ->for($table)
            ->create();

        $request = ReservationRequest::factory()->create([
            'restaurant_id' => $restaurant->id,
            'party_size' => 4,
            'desired_at' => $desiredAt,
        ]);

        $context = $this->builder->build($request);

        $this->assertSame('full', $context['availability']['slot_state']);
        $this->assertNotEmpty($context['availability']['alternative_slots']);
        foreach ($context['availability']['alternative_slots'] as $slot) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $slot);
        }
    }

    public function test_desired_at_is_returned_in_restaurant_local_time_even_when_request_is_utc(): void
    {
        $restaurant = Restaurant::factory()->create(['timezone' => 'Europe/Berlin']);

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
