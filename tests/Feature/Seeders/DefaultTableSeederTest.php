<?php

namespace Tests\Feature\Seeders;

use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\Table;
use Database\Seeders\DefaultTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultTableSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_default_table_for_a_restaurant_without_tables(): void
    {
        $restaurant = Restaurant::factory()->create();

        (new DefaultTableSeeder)->run();

        $this->assertSame(1, Table::where('restaurant_id', $restaurant->id)->count());

        $table = Table::where('restaurant_id', $restaurant->id)->sole();
        $this->assertSame('Tisch 1', $table->label);
        $this->assertSame(1, $table->sort_order);
        $this->assertTrue($table->active);
    }

    public function test_skips_restaurants_that_already_have_a_table(): void
    {
        $restaurant = Restaurant::factory()->create();
        Table::factory()->for($restaurant)->create(['label' => 'Existing']);

        (new DefaultTableSeeder)->run();

        $this->assertSame(1, Table::where('restaurant_id', $restaurant->id)->count());
        $this->assertSame('Existing', Table::where('restaurant_id', $restaurant->id)->sole()->label);
    }

    public function test_is_idempotent_on_repeated_runs(): void
    {
        $restaurant = Restaurant::factory()->create();

        (new DefaultTableSeeder)->run();
        (new DefaultTableSeeder)->run();
        (new DefaultTableSeeder)->run();

        $this->assertSame(1, Table::where('restaurant_id', $restaurant->id)->count());
    }

    public function test_sizes_the_default_table_by_max_historical_party_size_plus_four(): void
    {
        $restaurant = Restaurant::factory()->create();
        ReservationRequest::factory()->forRestaurant($restaurant)->create(['party_size' => 6]);
        ReservationRequest::factory()->forRestaurant($restaurant)->create(['party_size' => 9]);

        (new DefaultTableSeeder)->run();

        $table = Table::where('restaurant_id', $restaurant->id)->sole();
        $this->assertSame(13, $table->seats);
    }

    public function test_uses_fallback_seats_when_restaurant_has_no_reservations(): void
    {
        $restaurant = Restaurant::factory()->create();

        (new DefaultTableSeeder)->run();

        $table = Table::where('restaurant_id', $restaurant->id)->sole();
        $this->assertSame(8, $table->seats);
    }

    public function test_only_seeds_restaurants_that_lack_tables(): void
    {
        $hasTable = Restaurant::factory()->create();
        Table::factory()->for($hasTable)->create();

        $needsTable = Restaurant::factory()->create();

        (new DefaultTableSeeder)->run();

        $this->assertSame(1, Table::where('restaurant_id', $hasTable->id)->count());
        $this->assertSame(1, Table::where('restaurant_id', $needsTable->id)->count());
    }
}
