<?php

declare(strict_types=1);

namespace Tests\Unit\Waitlist;

use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Services\Waitlist\WaitlistBanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitlistBannerTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();

        // UTC + a wide dinner window so the slot maths is not confounded by
        // timezone conversion (covered separately by SlotAvailability).
        $dinner = [['from' => '17:00', 'to' => '23:00']];
        $this->restaurant = Restaurant::factory()->create([
            'timezone' => 'UTC',
            'slot_buffer_minutes' => 90,
            'opening_hours' => [
                'mon' => $dinner, 'tue' => $dinner, 'wed' => $dinner, 'thu' => $dinner,
                'fri' => $dinner, 'sat' => $dinner, 'sun' => $dinner,
            ],
        ]);
    }

    private function service(): WaitlistBanner
    {
        return app(WaitlistBanner::class);
    }

    private function waitlisted(string $desiredAtUtc, int $partySize = 2, ?Restaurant $restaurant = null): ReservationRequest
    {
        return ReservationRequest::factory()->for($restaurant ?? $this->restaurant)->create([
            'status' => ReservationStatus::Waitlisted,
            'desired_at' => CarbonImmutable::parse($desiredAtUtc, 'UTC'),
            'party_size' => $partySize,
        ]);
    }

    private function occupy(Table $table, string $desiredAtUtc): void
    {
        $reservation = ReservationRequest::factory()->for($this->restaurant)->create([
            'status' => ReservationStatus::Confirmed,
            'desired_at' => CarbonImmutable::parse($desiredAtUtc, 'UTC'),
            'party_size' => 2,
        ]);

        ReservationTableAssignment::factory()
            ->for($reservation, 'reservationRequest')
            ->for($table)
            ->create();
    }

    public function test_lists_only_waitlisted_requests_with_a_free_slot(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $waiting = $this->waitlisted('2026-06-15 19:00');
        // A non-waitlisted request at the same slot must be ignored.
        ReservationRequest::factory()->for($this->restaurant)->create([
            'status' => ReservationStatus::New,
            'desired_at' => CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            'party_size' => 2,
        ]);

        $result = $this->service()->eligibleNow($this->restaurant->id);

        $this->assertCount(1, $result);
        $this->assertSame($waiting->id, $result->first()->id);
    }

    public function test_excludes_waitlisted_requests_whose_desired_at_is_in_the_past(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->waitlisted('2020-01-01 19:00');

        $this->assertCount(0, $this->service()->eligibleNow($this->restaurant->id));
    }

    public function test_excludes_waitlisted_requests_whose_slot_is_full(): void
    {
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->occupy($table, '2026-06-15 19:00'); // the only table is now busy
        $this->waitlisted('2026-06-15 19:00', partySize: 4);

        $this->assertCount(0, $this->service()->eligibleNow($this->restaurant->id));
    }

    public function test_caps_results_at_twenty(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 8]);
        for ($i = 0; $i < 25; $i++) {
            $this->waitlisted('2026-06-15 19:00', partySize: 1);
        }

        $this->assertCount(20, $this->service()->eligibleNow($this->restaurant->id));
    }

    public function test_orders_by_desired_at_ascending(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 8]);
        $later = $this->waitlisted('2026-06-15 20:00');
        $earlier = $this->waitlisted('2026-06-15 18:00');

        $ids = $this->service()->eligibleNow($this->restaurant->id)->pluck('id')->all();

        $this->assertSame([$earlier->id, $later->id], $ids);
    }

    public function test_scopes_to_the_given_restaurant(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $other = Restaurant::factory()->create([
            'timezone' => 'UTC',
            'opening_hours' => $this->restaurant->opening_hours,
        ]);
        Table::factory()->for($other)->create(['seats' => 4]);
        $this->waitlisted('2026-06-15 19:00', restaurant: $other);

        $this->assertCount(0, $this->service()->eligibleNow($this->restaurant->id));
    }
}
