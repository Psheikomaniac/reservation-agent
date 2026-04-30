<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutoSendSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_restaurants_carry_the_send_mode_columns_with_documented_defaults(): void
    {
        $restaurant = Restaurant::factory()->create()->refresh();

        $this->assertSame('manual', $restaurant->getRawOriginal('send_mode'));
        $this->assertSame(10, (int) $restaurant->auto_send_party_size_max);
        $this->assertSame(90, (int) $restaurant->auto_send_min_lead_time_minutes);
        $this->assertNull($restaurant->send_mode_changed_at);
        $this->assertNull($restaurant->send_mode_changed_by);
    }

    public function test_send_mode_changed_by_nulls_out_when_the_referenced_user_is_deleted(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->forRestaurant($restaurant)->create();

        DB::table('restaurants')
            ->where('id', $restaurant->id)
            ->update(['send_mode_changed_by' => $user->id, 'send_mode_changed_at' => now()]);

        $user->delete();

        $this->assertNull(DB::table('restaurants')->where('id', $restaurant->id)->value('send_mode_changed_by'));
    }

    public function test_reservation_replies_carry_the_send_mode_snapshot_columns(): void
    {
        $request = ReservationRequest::factory()->create();
        $reply = ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
        ])->refresh();

        $this->assertNull($reply->send_mode_at_creation);
        $this->assertNull($reply->shadow_compared_at);
        $this->assertSame(0, (int) $reply->shadow_was_modified);
        $this->assertNull($reply->auto_send_decision);
        $this->assertNull($reply->auto_send_scheduled_for);
    }

    public function test_auto_send_audits_table_is_append_only_and_indexed_for_analytics(): void
    {
        $this->assertTrue(Schema::hasTable('auto_send_audits'));

        $columns = Schema::getColumnListing('auto_send_audits');

        // Append-only: created_at present, updated_at absent.
        $this->assertContains('created_at', $columns);
        $this->assertNotContains('updated_at', $columns);

        foreach (['reservation_reply_id', 'restaurant_id', 'send_mode', 'decision', 'reason', 'triggered_by_user_id'] as $column) {
            $this->assertContains($column, $columns, "Missing column: {$column}");
        }

        // PRD-008 analytics relies on the (restaurant_id, created_at) index.
        $indexes = collect(Schema::getIndexes('auto_send_audits'));
        $hasCompositeIndex = $indexes->contains(fn ($index) => $index['columns'] === ['restaurant_id', 'created_at']);

        $this->assertTrue($hasCompositeIndex, 'auto_send_audits needs a composite (restaurant_id, created_at) index for PRD-008 analytics.');
    }

    public function test_auto_send_audits_cascade_when_the_parent_reply_is_deleted(): void
    {
        $request = ReservationRequest::factory()->create();
        $reply = ReservationReply::factory()->create([
            'reservation_request_id' => $request->id,
        ]);

        DB::table('auto_send_audits')->insert([
            'reservation_reply_id' => $reply->id,
            'restaurant_id' => $request->restaurant_id,
            'send_mode' => 'manual',
            'decision' => 'allowed',
            'reason' => 'manual mode short-circuits decider',
            'created_at' => now(),
        ]);

        DB::table('reservation_replies')->where('id', $reply->id)->delete();

        $this->assertSame(0, DB::table('auto_send_audits')->count());
    }
}
