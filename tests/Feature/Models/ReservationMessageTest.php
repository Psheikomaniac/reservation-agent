<?php

namespace Tests\Feature\Models;

use App\Enums\MessageDirection;
use App\Models\ReservationMessage;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReservationMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_message(): void
    {
        $message = ReservationMessage::factory()->create();

        $this->assertTrue($message->exists);
        $this->assertNotEmpty($message->message_id);
        $this->assertInstanceOf(MessageDirection::class, $message->direction);
        $this->assertNotEmpty($message->raw_headers);
    }

    public function test_inbound_state_sets_received_at_and_clears_sent_at(): void
    {
        $message = ReservationMessage::factory()->inbound()->create();

        $this->assertSame(MessageDirection::In, $message->direction);
        $this->assertInstanceOf(Carbon::class, $message->received_at);
        $this->assertNull($message->sent_at);
    }

    public function test_outbound_state_sets_sent_at_and_clears_received_at(): void
    {
        $message = ReservationMessage::factory()->outbound()->create();

        $this->assertSame(MessageDirection::Out, $message->direction);
        $this->assertInstanceOf(Carbon::class, $message->sent_at);
        $this->assertNull($message->received_at);
    }

    public function test_belongs_to_reservation_request(): void
    {
        $request = ReservationRequest::factory()->create();
        $message = ReservationMessage::factory()->forReservationRequest($request)->create();

        $this->assertSame($request->id, $message->reservationRequest->id);
    }

    public function test_reservation_request_has_many_messages_in_creation_order(): void
    {
        $request = ReservationRequest::factory()->create();

        $first = ReservationMessage::factory()
            ->forReservationRequest($request)
            ->inbound()
            ->create(['created_at' => now()->subMinutes(10)]);
        $second = ReservationMessage::factory()
            ->forReservationRequest($request)
            ->outbound()
            ->create(['created_at' => now()->subMinutes(5)]);

        $messages = $request->messages()->orderBy('created_at')->get();

        $this->assertCount(2, $messages);
        $this->assertSame($first->id, $messages[0]->id);
        $this->assertSame($second->id, $messages[1]->id);
    }

    public function test_message_id_is_globally_unique(): void
    {
        $messageId = '<unique@example.test>';

        ReservationMessage::factory()->create(['message_id' => $messageId]);

        $this->expectException(QueryException::class);

        ReservationMessage::factory()->create(['message_id' => $messageId]);
    }

    public function test_message_id_is_unique_across_restaurants(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();

        $requestA = ReservationRequest::factory()->forRestaurant($restaurantA)->create();
        $requestB = ReservationRequest::factory()->forRestaurant($restaurantB)->create();

        $messageId = '<cross-tenant@example.test>';

        ReservationMessage::factory()
            ->forReservationRequest($requestA)
            ->create(['message_id' => $messageId]);

        $this->expectException(QueryException::class);

        ReservationMessage::factory()
            ->forReservationRequest($requestB)
            ->create(['message_id' => $messageId]);
    }

    public function test_messages_cascade_delete_when_reservation_request_is_deleted(): void
    {
        $request = ReservationRequest::factory()->create();
        ReservationMessage::factory()->forReservationRequest($request)->count(3)->create();

        $other = ReservationRequest::factory()->create();
        ReservationMessage::factory()->forReservationRequest($other)->create();

        $this->assertSame(4, ReservationMessage::query()->count());

        $request->delete();

        $this->assertSame(1, ReservationMessage::query()->count());
    }

    public function test_table_has_composite_index_on_request_id_and_created_at(): void
    {
        $this->assertTrue(
            collect(Schema::getIndexes('reservation_messages'))
                ->contains(fn (array $index) => $index['columns'] === ['reservation_request_id', 'created_at']),
            'reservation_messages must have a composite index on (reservation_request_id, created_at)'
        );
    }

    public function test_direction_is_cast_to_enum(): void
    {
        $message = ReservationMessage::factory()->create(['direction' => 'in']);

        $this->assertSame(MessageDirection::In, $message->fresh()->direction);
    }

    public function test_references_column_can_persist_long_chains(): void
    {
        $references = collect(range(1, 20))
            ->map(fn (int $i) => '<msg-'.$i.'@example.test>')
            ->implode(' ');

        $message = ReservationMessage::factory()->create(['references' => $references]);

        $this->assertSame($references, $message->fresh()->references);
    }
}
