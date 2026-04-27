<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Resources;

use App\Enums\ReservationSource;
use App\Http\Resources\ReservationRequestDetailResource;
use App\Models\ReservationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReservationRequestDetailResourceTest extends TestCase
{
    use RefreshDatabase;

    private function toArray(ReservationRequest $request): array
    {
        return (new ReservationRequestDetailResource($request))->toArray(Request::create('/'));
    }

    public function test_detail_response_carries_full_list_shape_plus_message_raw_payload_and_email_body(): void
    {
        $request = ReservationRequest::factory()->create([
            'source' => ReservationSource::Email,
            'message' => 'Allergie: Nüsse',
            'raw_payload' => [
                'body' => "Hallo,\nwir möchten am Freitag um 19:00 Uhr für 4 Personen reservieren.",
                'sender_email' => 'guest@example.test',
                'sender_name' => 'Anna Probe',
                'message_id' => '<abc@example.test>',
            ],
        ]);

        $payload = $this->toArray($request);

        $this->assertSame([
            'id',
            'status',
            'source',
            'guest_name',
            'guest_email',
            'guest_phone',
            'party_size',
            'desired_at',
            'needs_manual_review',
            'created_at',
            'has_raw_email',
            'message',
            'raw_payload',
            'raw_email_body',
            'latest_reply',
        ], array_keys($payload));

        $this->assertSame('Allergie: Nüsse', $payload['message']);
        $this->assertSame([
            'body' => "Hallo,\nwir möchten am Freitag um 19:00 Uhr für 4 Personen reservieren.",
            'sender_email' => 'guest@example.test',
            'sender_name' => 'Anna Probe',
            'message_id' => '<abc@example.test>',
        ], $payload['raw_payload']);
        $this->assertSame(
            "Hallo,\nwir möchten am Freitag um 19:00 Uhr für 4 Personen reservieren.",
            $payload['raw_email_body']
        );
        $this->assertTrue($payload['has_raw_email']);
    }

    public function test_raw_email_body_is_null_for_web_form_source(): void
    {
        $request = ReservationRequest::factory()->create([
            'source' => ReservationSource::WebForm,
            'raw_payload' => [
                'guest_name' => 'Anna Probe',
                'party_size' => 4,
                'desired_at' => '2026-05-12 19:00',
            ],
        ]);

        $payload = $this->toArray($request);

        $this->assertNull($payload['raw_email_body']);
        $this->assertFalse($payload['has_raw_email']);
        // Form payload is still surfaced via raw_payload itself.
        $this->assertSame([
            'guest_name' => 'Anna Probe',
            'party_size' => 4,
            'desired_at' => '2026-05-12 19:00',
        ], $payload['raw_payload']);
    }

    public function test_raw_email_body_is_null_when_email_payload_lacks_body_key(): void
    {
        $request = ReservationRequest::factory()->create([
            'source' => ReservationSource::Email,
            'raw_payload' => ['sender_email' => 'guest@example.test'],
        ]);

        $payload = $this->toArray($request);

        $this->assertNull($payload['raw_email_body']);
    }

    public function test_raw_payload_is_null_when_not_persisted(): void
    {
        $request = ReservationRequest::factory()->create([
            'source' => ReservationSource::WebForm,
            'raw_payload' => null,
        ]);

        $payload = $this->toArray($request);

        $this->assertNull($payload['raw_payload']);
        $this->assertNull($payload['raw_email_body']);
    }
}
