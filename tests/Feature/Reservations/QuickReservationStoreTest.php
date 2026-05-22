<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class QuickReservationStoreTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // UTC + a wide dinner window keeps local time == stored desired_at, so
        // the booking logic under test is not confounded by timezone maths
        // (the timezone path is covered by the create endpoint test).
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'source' => 'phone',
            'date' => '2026-06-15', // Monday
            'time' => '19:00',
            'party_size' => 2,
            'guest_name' => 'Müller',
        ], $overrides);
    }

    private function occupy(Table $table, string $desiredAtUtc): void
    {
        $reservation = ReservationRequest::factory()->for($this->restaurant)->create([
            'desired_at' => CarbonImmutable::parse($desiredAtUtc, 'UTC'),
            'party_size' => 2,
            'status' => ReservationStatus::Confirmed,
        ]);

        ReservationTableAssignment::factory()
            ->for($reservation, 'reservationRequest')
            ->for($table)
            ->create();
    }

    public function test_guests_are_redirected(): void
    {
        $this->post(route('reservations.quick.store'), $this->payload())->assertRedirect('/login');
    }

    public function test_owner_can_create_a_phone_reservation(): void
    {
        Mail::fake();
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload([
                'guest_phone' => '+49 157 1234',
                'guest_email' => 'gast@example.com',
                'note' => 'Geburtstag',
            ]))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');

        $reservation = ReservationRequest::sole();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(ReservationSource::Phone, $reservation->source);
        $this->assertSame('Müller', $reservation->guest_name);
        $this->assertSame('Geburtstag', $reservation->note);
        $this->assertSame($this->owner->id, $reservation->created_by_user_id);
        $this->assertSame('2026-06-15 19:00:00', $reservation->desired_at->utc()->format('Y-m-d H:i:s'));

        $assignment = ReservationTableAssignment::sole();
        $this->assertSame($table->id, $assignment->table_id);
        $this->assertSame($this->owner->id, $assignment->assigned_by_user_id);

        $this->assertSame(0, ReservationReply::count());
        Mail::assertNothingSent();
    }

    public function test_owner_can_create_a_walk_in_reservation(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['source' => 'walk_in']))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(ReservationSource::WalkIn, ReservationRequest::sole()->source);
    }

    public function test_it_auto_assigns_the_smallest_fitting_table(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 8, 'sort_order' => 1]);
        $small = Table::factory()->for($this->restaurant)->create(['seats' => 4, 'sort_order' => 2]);

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['party_size' => 4]))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($small->id, ReservationTableAssignment::sole()->table_id);
    }

    public function test_it_assigns_every_table_of_a_combination(): void
    {
        $a = Table::factory()->for($this->restaurant)->create(['seats' => 2, 'sort_order' => 1]);
        $b = Table::factory()->for($this->restaurant)->create(['seats' => 2, 'sort_order' => 2]);
        $a->update(['combinable_with' => [$b->id]]);
        $b->update(['combinable_with' => [$a->id]]);

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['party_size' => 4]))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(2, ReservationTableAssignment::count());
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            ReservationTableAssignment::pluck('table_id')->all(),
        );
    }

    public function test_it_accepts_a_manual_table_override(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4, 'sort_order' => 1]);
        $preferred = Table::factory()->for($this->restaurant)->create(['seats' => 8, 'sort_order' => 2]);

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['table_id' => $preferred->id]))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($preferred->id, ReservationTableAssignment::sole()->table_id);
    }

    public function test_it_rejects_a_manual_table_that_is_busy_in_the_slot(): void
    {
        $table = Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $this->occupy($table, '2026-06-15 19:00');

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['table_id' => $table->id]))
            ->assertSessionHasErrors('table_id');

        $this->assertSame(1, ReservationRequest::count()); // only the pre-existing occupier
    }

    public function test_it_rejects_when_no_table_fits_the_party(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 2]);

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['party_size' => 6]))
            ->assertSessionHasErrors('table_id');

        $this->assertSame(0, ReservationRequest::count());
        $this->assertSame(0, ReservationTableAssignment::count());
    }

    public function test_a_second_booking_for_the_only_table_is_rejected(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload())
            ->assertRedirect(route('dashboard'));

        // Same slot, only table now occupied: the in-transaction re-check rejects.
        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload())
            ->assertSessionHasErrors('table_id');

        $this->assertSame(1, ReservationRequest::count());
        $this->assertSame(1, ReservationTableAssignment::count());
    }

    public function test_a_foreign_restaurant_table_id_is_rejected(): void
    {
        Table::factory()->for($this->restaurant)->create(['seats' => 4]);
        $foreign = Restaurant::factory()->create();
        $foreignTable = Table::factory()->for($foreign)->create(['seats' => 4]);

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['table_id' => $foreignTable->id]))
            ->assertSessionHasErrors('table_id');
    }

    public function test_past_date_is_rejected(): void
    {
        Table::factory()->for($this->restaurant)->create();

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['date' => '2020-01-01']))
            ->assertSessionHasErrors('date');
    }

    public function test_party_size_above_the_cap_is_rejected(): void
    {
        Table::factory()->for($this->restaurant)->create();

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['party_size' => 21]))
            ->assertSessionHasErrors('party_size');
    }

    public function test_source_must_be_phone_or_walk_in(): void
    {
        Table::factory()->for($this->restaurant)->create();

        $this->actingAs($this->owner)
            ->post(route('reservations.quick.store'), $this->payload(['source' => 'web_form']))
            ->assertSessionHasErrors('source');
    }

    public function test_user_without_a_restaurant_is_forbidden(): void
    {
        $user = User::factory()->create(); // restaurant_id is null

        $this->actingAs($user)
            ->post(route('reservations.quick.store'), $this->payload())
            ->assertForbidden();
    }
}
