<?php

namespace Tests\Feature\Models;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_reservation_request_with_default_status_new(): void
    {
        $request = ReservationRequest::factory()->create();

        $this->assertTrue($request->exists);
        $this->assertSame(ReservationStatus::New, $request->status);
        $this->assertSame(ReservationSource::WebForm, $request->source);
        $this->assertNotEmpty($request->guest_name);
        $this->assertIsInt($request->party_size);
        $this->assertFalse($request->needs_manual_review);
    }

    public function test_in_review_state_sets_status_to_in_review(): void
    {
        $request = ReservationRequest::factory()->inReview()->create();

        $this->assertSame(ReservationStatus::InReview, $request->status);
    }

    public function test_confirmed_state_sets_status_to_confirmed(): void
    {
        $request = ReservationRequest::factory()->confirmed()->create();

        $this->assertSame(ReservationStatus::Confirmed, $request->status);
    }

    public function test_for_restaurant_state_attaches_to_provided_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();

        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();

        $this->assertSame($restaurant->id, $request->restaurant_id);
    }

    public function test_source_and_status_are_persisted_as_string_values_and_cast_back_to_enums(): void
    {
        $request = ReservationRequest::factory()->create([
            'source' => ReservationSource::Email,
            'status' => ReservationStatus::Replied,
        ]);

        $row = DB::table('reservation_requests')->where('id', $request->id)->first();

        $this->assertSame('email', $row->source);
        $this->assertSame('replied', $row->status);
        $this->assertSame(ReservationSource::Email, $request->fresh()->source);
        $this->assertSame(ReservationStatus::Replied, $request->fresh()->status);
    }

    public function test_desired_at_is_cast_to_a_carbon_instance(): void
    {
        $request = ReservationRequest::factory()->create([
            'desired_at' => '2026-05-01 19:30:00',
        ]);

        $this->assertInstanceOf(Carbon::class, $request->desired_at);
        $this->assertSame('2026-05-01 19:30:00', $request->desired_at->format('Y-m-d H:i:s'));
    }

    public function test_desired_at_may_be_null_when_not_parsable(): void
    {
        $request = ReservationRequest::factory()->create(['desired_at' => null]);

        $this->assertNull($request->fresh()->desired_at);
    }

    public function test_raw_payload_is_encrypted_at_rest_and_decrypted_on_read(): void
    {
        $payload = [
            'form' => 'web',
            'fields' => ['name' => 'Erika Mustermann', 'party_size' => 4],
        ];

        $request = ReservationRequest::factory()->create(['raw_payload' => $payload]);

        $rawValue = DB::table('reservation_requests')->where('id', $request->id)->value('raw_payload');

        $this->assertNotNull($rawValue);
        $this->assertNotSame(json_encode($payload), $rawValue, 'raw_payload must not be stored as plain JSON');
        $this->assertSame($payload, json_decode(Crypt::decryptString($rawValue), true));
        $this->assertSame($payload, $request->fresh()->raw_payload);
    }

    public function test_needs_manual_review_defaults_to_false_when_omitted(): void
    {
        $restaurant = Restaurant::factory()->create();

        DB::table('reservation_requests')->insert([
            'restaurant_id' => $restaurant->id,
            'source' => ReservationSource::WebForm->value,
            'status' => ReservationStatus::New->value,
            'guest_name' => 'Test Guest',
            'party_size' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $request = ReservationRequest::query()->sole();

        $this->assertFalse($request->needs_manual_review);
    }

    public function test_email_message_id_is_nullable_and_fillable(): void
    {
        $request = ReservationRequest::factory()->create([
            'source' => ReservationSource::Email,
            'email_message_id' => '<abc123@example.com>',
        ]);

        $this->assertSame('<abc123@example.com>', $request->fresh()->email_message_id);
    }

    public function test_email_message_id_allows_multiple_null_rows(): void
    {
        ReservationRequest::factory()->count(2)->create(['email_message_id' => null]);

        $this->assertSame(2, ReservationRequest::query()->whereNull('email_message_id')->count());
    }

    public function test_duplicate_email_message_id_is_rejected_at_the_database_level(): void
    {
        ReservationRequest::factory()->create([
            'source' => ReservationSource::Email,
            'email_message_id' => '<dup@example.com>',
        ]);

        $this->expectException(QueryException::class);

        ReservationRequest::factory()->create([
            'source' => ReservationSource::Email,
            'email_message_id' => '<dup@example.com>',
        ]);
    }

    public function test_reservation_requests_are_cascade_deleted_with_their_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $otherRestaurant = Restaurant::factory()->create();

        ReservationRequest::factory()->forRestaurant($restaurant)->count(3)->create();
        ReservationRequest::factory()->forRestaurant($otherRestaurant)->create();

        $this->assertSame(4, ReservationRequest::query()->count());

        $restaurant->delete();

        $this->assertSame(1, ReservationRequest::query()->count());
        $this->assertSame(
            $otherRestaurant->id,
            ReservationRequest::query()->sole()->restaurant_id
        );
    }

    public function test_restaurant_id_is_required(): void
    {
        $this->expectException(QueryException::class);

        DB::table('reservation_requests')->insert([
            'source' => ReservationSource::WebForm->value,
            'status' => ReservationStatus::New->value,
            'guest_name' => 'Test Guest',
            'party_size' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_belongs_to_restaurant_relation_returns_owning_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Bella Italia']);

        $request = ReservationRequest::factory()->forRestaurant($restaurant)->create();

        $this->assertSame($restaurant->id, $request->restaurant->id);
        $this->assertSame('Bella Italia', $request->restaurant->name);
    }

    public function test_replies_relation_returns_all_attached_replies(): void
    {
        $request = ReservationRequest::factory()->create();
        $otherRequest = ReservationRequest::factory()->create();

        ReservationReply::factory()->forReservationRequest($request)->count(3)->create();
        ReservationReply::factory()->forReservationRequest($otherRequest)->create();

        $this->assertCount(3, $request->replies);
        $this->assertCount(1, $otherRequest->replies);
    }

    public function test_latest_reply_returns_the_most_recently_created_reply(): void
    {
        $request = ReservationRequest::factory()->create();

        ReservationReply::factory()->forReservationRequest($request)->create([
            'body' => 'First draft',
            'created_at' => now()->subHours(2),
        ]);
        $newest = ReservationReply::factory()->forReservationRequest($request)->create([
            'body' => 'Latest revision',
            'created_at' => now(),
        ]);
        ReservationReply::factory()->forReservationRequest($request)->create([
            'body' => 'Middle draft',
            'created_at' => now()->subHour(),
        ]);

        $this->assertSame($newest->id, $request->latestReply->id);
        $this->assertSame('Latest revision', $request->latestReply->body);
    }
}
