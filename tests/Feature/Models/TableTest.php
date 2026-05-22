<?php

namespace Tests\Feature\Models;

use App\Models\ReservationRequest;
use App\Models\ReservationTableAssignment;
use App\Models\Restaurant;
use App\Models\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TableTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_a_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $table = Table::factory()->for($restaurant)->create();

        $this->assertTrue($table->restaurant->is($restaurant));
    }

    public function test_casts_combinable_with_to_array(): void
    {
        $table = Table::factory()->create([
            'combinable_with' => [1, 2, 3],
        ]);

        $this->assertSame([1, 2, 3], $table->fresh()->combinable_with);
    }

    public function test_casts_active_to_boolean(): void
    {
        $table = Table::factory()->inactive()->create();

        $this->assertFalse($table->fresh()->active);
    }

    public function test_restaurant_has_many_tables(): void
    {
        $restaurant = Restaurant::factory()->create();
        Table::factory()->for($restaurant)->count(3)->create();
        Table::factory()->for($restaurant)->inactive()->create();

        $this->assertCount(4, $restaurant->fresh()->tables);
    }

    public function test_restaurant_casts_slot_buffer_minutes_as_integer(): void
    {
        $restaurant = Restaurant::factory()->create([
            'slot_buffer_minutes' => '120',
        ]);

        $this->assertSame(120, $restaurant->fresh()->slot_buffer_minutes);
    }

    public function test_reservation_has_many_table_assignments(): void
    {
        $reservation = ReservationRequest::factory()->create();
        $table = Table::factory()->for($reservation->restaurant)->create();

        ReservationTableAssignment::factory()
            ->for($reservation, 'reservationRequest')
            ->for($table)
            ->create();

        $this->assertCount(1, $reservation->fresh()->tableAssignments);
    }

    public function test_table_assignment_belongs_to_reservation_table_and_optional_user(): void
    {
        $assignment = ReservationTableAssignment::factory()->create();

        $this->assertNotNull($assignment->reservationRequest);
        $this->assertNotNull($assignment->table);
        $this->assertNull($assignment->assignedBy);
    }
}
