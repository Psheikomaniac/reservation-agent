<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WaitlistFlowTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $dinner = [['from' => '17:00', 'to' => '23:00']];
        $this->restaurant = Restaurant::factory()->create([
            'timezone' => 'UTC',
            'slot_buffer_minutes' => 90,
            'opening_hours' => [
                'mon' => $dinner, 'tue' => $dinner, 'wed' => $dinner, 'thu' => $dinner,
                'fri' => $dinner, 'sat' => $dinner, 'sun' => $dinner,
            ],
        ]);
        $this->owner = User::factory()->forRestaurant($this->restaurant)->create(['role' => UserRole::Owner]);
    }

    private function waitlisted(string $desiredAtUtc, int $partySize = 2, ?Restaurant $restaurant = null): ReservationRequest
    {
        return ReservationRequest::factory()->for($restaurant ?? $this->restaurant)->create([
            'status' => ReservationStatus::Waitlisted,
            'desired_at' => CarbonImmutable::parse($desiredAtUtc, 'UTC'),
            'party_size' => $partySize,
        ]);
    }

    public function test_banner_lists_eligible_waitlisted_reservations(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $waiting = $this->waitlisted('2026-06-15 19:00');

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('waitlistBanner', 1)
                ->where('waitlistBanner.0.id', $waiting->id)
            );
    }

    public function test_banner_excludes_a_waitlisted_request_whose_slot_is_full(): void
    {
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        // Confirmed + a table assignment is what makes busyTableIds see the only
        // table as taken, so the slot is genuinely Full for the waiting party.
        $busy = ReservationRequest::factory()->for($this->restaurant)->create([
            'status' => ReservationStatus::Confirmed,
            'desired_at' => CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            'party_size' => 2,
        ]);
        ReservationTableAssignment::factory()->for($busy, 'reservationRequest')->for($table)->create();

        $this->waitlisted('2026-06-15 19:00', partySize: 4);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('waitlistBanner', 0));
    }

    public function test_banner_appears_after_a_cancellation_frees_the_slot(): void
    {
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $confirmed = ReservationRequest::factory()->for($this->restaurant)->create([
            'status' => ReservationStatus::Confirmed,
            'desired_at' => CarbonImmutable::parse('2026-06-15 19:00', 'UTC'),
            'party_size' => 4,
        ]);
        ReservationTableAssignment::factory()->for($confirmed, 'reservationRequest')->for($table)->create();
        $waiting = $this->waitlisted('2026-06-15 19:00', partySize: 4);

        // Slot is full → the waiting guest is hidden.
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('waitlistBanner', 0));

        // Cancelling frees the table (Cancelled is non-occupying).
        $confirmed->update(['status' => ReservationStatus::Cancelled]);

        // Next load: the slot is free again, so the waiting guest surfaces.
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('waitlistBanner', 1)
                ->where('waitlistBanner.0.id', $waiting->id)
            );
    }

    public function test_user_without_a_restaurant_sees_an_empty_banner(): void
    {
        $user = User::factory()->create(); // restaurant_id is null

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('waitlistBanner', 0));
    }

    public function test_banner_disappears_after_the_waitlisted_request_is_confirmed(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $waiting = $this->waitlisted('2026-06-15 19:00');

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('waitlistBanner', 1));

        $waiting->update(['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('waitlistBanner', 0));
    }

    public function test_dashboard_filter_shows_only_waitlisted_requests(): void
    {
        $waiting = $this->waitlisted('2026-06-15 19:00');
        ReservationRequest::factory()->for($this->restaurant)->create(['status' => ReservationStatus::New]);
        ReservationRequest::factory()->for($this->restaurant)->create(['status' => ReservationStatus::Confirmed]);

        $this->actingAs($this->owner)
            ->get(route('dashboard', ['status' => [ReservationStatus::Waitlisted->value]]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.status.0', 'waitlisted') // the value was accepted, not stripped
                ->has('requests.data', 1)
                ->where('requests.data.0.id', $waiting->id)
                ->where('requests.data.0.status', 'waitlisted')
            );
    }

    public function test_user_from_another_restaurant_does_not_see_the_banner(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->waitlisted('2026-06-15 19:00'); // belongs to $this->restaurant

        $other = Restaurant::factory()->create(['timezone' => 'UTC', 'opening_hours' => $this->restaurant->opening_hours]);
        $otherOwner = User::factory()->forRestaurant($other)->create(['role' => UserRole::Owner]);

        $this->actingAs($otherOwner)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('waitlistBanner', 0));
    }
}
