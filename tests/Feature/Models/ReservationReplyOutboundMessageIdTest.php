<?php

namespace Tests\Feature\Models;

use App\Models\ReservationReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReservationReplyOutboundMessageIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_outbound_message_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('reservation_replies', 'outbound_message_id'));
    }

    public function test_outbound_message_id_defaults_to_null(): void
    {
        $reply = ReservationReply::factory()->draft()->create();

        $this->assertNull($reply->outbound_message_id);
    }

    public function test_outbound_message_id_is_persisted_when_set(): void
    {
        $messageId = '<outbound-42@example.test>';

        $reply = ReservationReply::factory()->sent()->create([
            'outbound_message_id' => $messageId,
        ]);

        $this->assertSame($messageId, $reply->fresh()->outbound_message_id);
    }
}
