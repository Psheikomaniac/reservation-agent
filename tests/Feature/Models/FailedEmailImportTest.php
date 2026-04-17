<?php

namespace Tests\Feature\Models;

use App\Models\FailedEmailImport;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FailedEmailImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_failed_email_import(): void
    {
        $failure = FailedEmailImport::factory()->create();

        $this->assertTrue($failure->exists);
        $this->assertNotEmpty($failure->message_id);
        $this->assertNotEmpty($failure->raw_headers);
        $this->assertNotEmpty($failure->raw_body);
        $this->assertNotEmpty($failure->error);
    }

    public function test_for_restaurant_state_attaches_to_provided_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();

        $failure = FailedEmailImport::factory()->forRestaurant($restaurant)->create();

        $this->assertSame($restaurant->id, $failure->restaurant_id);
    }

    public function test_belongs_to_restaurant_returns_owning_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Trattoria Milano']);

        $failure = FailedEmailImport::factory()->forRestaurant($restaurant)->create();

        $this->assertSame($restaurant->id, $failure->restaurant->id);
        $this->assertSame('Trattoria Milano', $failure->restaurant->name);
    }

    public function test_failed_imports_cascade_delete_when_their_restaurant_is_deleted(): void
    {
        $restaurant = Restaurant::factory()->create();
        $other = Restaurant::factory()->create();

        FailedEmailImport::factory()->forRestaurant($restaurant)->count(3)->create();
        FailedEmailImport::factory()->forRestaurant($other)->create();

        $this->assertSame(4, FailedEmailImport::query()->count());

        $restaurant->delete();

        $this->assertSame(1, FailedEmailImport::query()->count());
        $this->assertSame($other->id, FailedEmailImport::query()->sole()->restaurant_id);
    }

    public function test_created_at_is_populated_as_a_carbon_instance(): void
    {
        $failure = FailedEmailImport::factory()->create();

        $this->assertInstanceOf(Carbon::class, $failure->created_at);
        $this->assertNotNull($failure->fresh()->created_at);
    }

    public function test_table_has_no_updated_at_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('failed_email_imports', 'updated_at'),
            'failed_email_imports is append-only and must not have an updated_at column'
        );
    }

    public function test_saving_an_existing_record_does_not_attempt_to_write_updated_at(): void
    {
        $failure = FailedEmailImport::factory()->create();

        $failure->error = 'Updated error message';
        $failure->save();

        $this->assertSame('Updated error message', $failure->fresh()->error);
    }

    public function test_large_raw_body_is_persisted_intact(): void
    {
        $body = str_repeat("Dear staff,\nI would like to reserve a table.\n", 200);

        $failure = FailedEmailImport::factory()->create(['raw_body' => $body]);

        $this->assertSame($body, $failure->fresh()->raw_body);
    }

    public function test_message_id_is_not_unique_so_retries_may_create_repeat_entries(): void
    {
        $restaurant = Restaurant::factory()->create();
        $messageId = '<duplicate@mail.example.com>';

        FailedEmailImport::factory()->forRestaurant($restaurant)->create(['message_id' => $messageId]);
        FailedEmailImport::factory()->forRestaurant($restaurant)->create(['message_id' => $messageId]);

        $this->assertSame(2, FailedEmailImport::query()->where('message_id', $messageId)->count());
    }
}
