<?php

namespace Tests\Feature\Models;

use App\Enums\ReservationReplyStatus;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReservationReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_default_creates_a_draft_reply(): void
    {
        $reply = ReservationReply::factory()->create();

        $this->assertTrue($reply->exists);
        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);
        $this->assertNotEmpty($reply->body);
        $this->assertNull($reply->approved_by);
        $this->assertNull($reply->approved_at);
        $this->assertNull($reply->sent_at);
        $this->assertNull($reply->error_message);
    }

    public function test_draft_state_resets_approval_fields(): void
    {
        $reply = ReservationReply::factory()->draft()->create();

        $this->assertSame(ReservationReplyStatus::Draft, $reply->status);
        $this->assertNull($reply->approved_by);
        $this->assertNull($reply->approved_at);
        $this->assertNull($reply->sent_at);
    }

    public function test_approved_state_sets_approver_and_approved_at(): void
    {
        $reply = ReservationReply::factory()->approved()->create();

        $this->assertSame(ReservationReplyStatus::Approved, $reply->status);
        $this->assertNotNull($reply->approved_by);
        $this->assertInstanceOf(Carbon::class, $reply->approved_at);
        $this->assertNull($reply->sent_at);
        $this->assertNull($reply->error_message);
    }

    public function test_sent_state_sets_sent_at_in_addition_to_approval_fields(): void
    {
        $reply = ReservationReply::factory()->sent()->create();

        $this->assertSame(ReservationReplyStatus::Sent, $reply->status);
        $this->assertNotNull($reply->approved_by);
        $this->assertInstanceOf(Carbon::class, $reply->approved_at);
        $this->assertInstanceOf(Carbon::class, $reply->sent_at);
        $this->assertNull($reply->error_message);
    }

    public function test_failed_state_records_error_message_and_keeps_sent_at_null(): void
    {
        $reply = ReservationReply::factory()->failed()->create();

        $this->assertSame(ReservationReplyStatus::Failed, $reply->status);
        $this->assertNotNull($reply->approved_by);
        $this->assertInstanceOf(Carbon::class, $reply->approved_at);
        $this->assertNull($reply->sent_at);
        $this->assertNotEmpty($reply->error_message);
    }

    public function test_for_reservation_request_state_attaches_to_provided_request(): void
    {
        $request = ReservationRequest::factory()->create();

        $reply = ReservationReply::factory()->forReservationRequest($request)->create();

        $this->assertSame($request->id, $reply->reservation_request_id);
    }

    public function test_status_is_persisted_as_string_value_and_cast_back_to_enum(): void
    {
        $reply = ReservationReply::factory()->approved()->create();

        $rawStatus = DB::table('reservation_replies')->where('id', $reply->id)->value('status');

        $this->assertSame('approved', $rawStatus);
        $this->assertSame(ReservationReplyStatus::Approved, $reply->fresh()->status);
    }

    public function test_ai_prompt_snapshot_is_cast_to_an_array(): void
    {
        $snapshot = [
            'restaurant' => ['name' => 'Bella Italia', 'tonality' => 'formal'],
            'request' => ['party_size' => 4, 'desired_at' => '2026-05-01T19:30:00Z'],
            'availability' => ['slots' => ['19:00', '19:30', '20:00']],
        ];

        $reply = ReservationReply::factory()->create(['ai_prompt_snapshot' => $snapshot]);

        $this->assertSame($snapshot, $reply->fresh()->ai_prompt_snapshot);
    }

    public function test_ai_prompt_snapshot_may_be_null(): void
    {
        $reply = ReservationReply::factory()->create(['ai_prompt_snapshot' => null]);

        $this->assertNull($reply->fresh()->ai_prompt_snapshot);
    }

    public function test_approved_at_and_sent_at_round_trip_as_carbon(): void
    {
        $reply = ReservationReply::factory()->create([
            'approved_at' => '2026-05-01 19:30:00',
            'sent_at' => '2026-05-01 19:31:00',
        ]);

        $fresh = $reply->fresh();

        $this->assertInstanceOf(Carbon::class, $fresh->approved_at);
        $this->assertInstanceOf(Carbon::class, $fresh->sent_at);
        $this->assertSame('2026-05-01 19:30:00', $fresh->approved_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-01 19:31:00', $fresh->sent_at->format('Y-m-d H:i:s'));
    }

    public function test_belongs_to_reservation_request_relation_returns_parent(): void
    {
        $request = ReservationRequest::factory()->create(['guest_name' => 'Mara Schmidt']);

        $reply = ReservationReply::factory()->forReservationRequest($request)->create();

        $this->assertSame($request->id, $reply->reservationRequest->id);
        $this->assertSame('Mara Schmidt', $reply->reservationRequest->guest_name);
    }

    public function test_approver_relation_returns_the_approving_user(): void
    {
        $approver = User::factory()->create(['name' => 'Tom Werner']);

        $reply = ReservationReply::factory()->approved()->create([
            'approved_by' => $approver->id,
        ]);

        $this->assertSame($approver->id, $reply->approver->id);
        $this->assertSame('Tom Werner', $reply->approver->name);
    }

    public function test_approver_relation_is_null_when_approved_by_is_null(): void
    {
        $reply = ReservationReply::factory()->draft()->create();

        $this->assertNull($reply->approver);
    }

    public function test_replies_cascade_delete_when_their_reservation_request_is_deleted(): void
    {
        $request = ReservationRequest::factory()->create();
        $otherRequest = ReservationRequest::factory()->create();

        ReservationReply::factory()->forReservationRequest($request)->count(2)->create();
        ReservationReply::factory()->forReservationRequest($otherRequest)->create();

        $this->assertSame(3, ReservationReply::query()->count());

        $request->delete();

        $this->assertSame(1, ReservationReply::query()->count());
        $this->assertSame(
            $otherRequest->id,
            ReservationReply::query()->sole()->reservation_request_id
        );
    }

    public function test_approved_by_is_nulled_when_approving_user_is_deleted(): void
    {
        $approver = User::factory()->create();
        $reply = ReservationReply::factory()->sent()->create([
            'approved_by' => $approver->id,
        ]);

        $this->assertSame($approver->id, $reply->fresh()->approved_by);

        $approver->delete();

        $fresh = $reply->fresh();
        $this->assertNotNull($fresh, 'Reply must survive approver deletion');
        $this->assertNull($fresh->approved_by, 'approved_by must be nulled on user delete');
        $this->assertNotNull($fresh->approved_at, 'approved_at audit timestamp must remain');
        $this->assertNotNull($fresh->sent_at, 'sent_at audit timestamp must remain');
    }
}
