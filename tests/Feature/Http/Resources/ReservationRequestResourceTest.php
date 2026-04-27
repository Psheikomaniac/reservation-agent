<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Resources;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Http\Resources\ReservationRequestResource;
use App\Models\ReservationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReservationRequestResourceTest extends TestCase
{
    use RefreshDatabase;

    private function toArray(ReservationRequest $request): array
    {
        return (new ReservationRequestResource($request))->toArray(Request::create('/'));
    }

    public function test_resource_returns_the_full_dashboard_contract(): void
    {
        $request = ReservationRequest::factory()->create([
            'status' => ReservationStatus::New,
            'source' => ReservationSource::WebForm,
            'guest_name' => 'Heiko Rademacher',
            'guest_email' => 'heiko@example.test',
            'guest_phone' => '+49 30 1234567',
            'party_size' => 4,
            'desired_at' => '2026-05-12 19:30:00',
            'needs_manual_review' => false,
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
        ], array_keys($payload));

        $this->assertSame($request->id, $payload['id']);
        $this->assertSame('new', $payload['status']);
        $this->assertSame('web_form', $payload['source']);
        $this->assertSame('Heiko Rademacher', $payload['guest_name']);
        $this->assertSame('heiko@example.test', $payload['guest_email']);
        $this->assertSame('+49 30 1234567', $payload['guest_phone']);
        $this->assertSame(4, $payload['party_size']);
        $this->assertSame('2026-05-12T19:30:00+00:00', $payload['desired_at']);
        $this->assertFalse($payload['needs_manual_review']);
        $this->assertSame($request->created_at->toIso8601String(), $payload['created_at']);
        $this->assertFalse($payload['has_raw_email']);
    }

    public function test_desired_at_is_null_when_unparseable(): void
    {
        $request = ReservationRequest::factory()->create([
            'desired_at' => null,
        ]);

        $payload = $this->toArray($request);

        $this->assertNull($payload['desired_at']);
    }

    public function test_has_raw_email_is_true_when_source_is_email(): void
    {
        $request = ReservationRequest::factory()->create([
            'source' => ReservationSource::Email,
        ]);

        $payload = $this->toArray($request);

        $this->assertTrue($payload['has_raw_email']);
        $this->assertSame('email', $payload['source']);
    }

    public function test_has_raw_email_is_false_when_source_is_web_form(): void
    {
        $request = ReservationRequest::factory()->create([
            'source' => ReservationSource::WebForm,
        ]);

        $this->assertFalse($this->toArray($request)['has_raw_email']);
    }

    public function test_raw_payload_is_not_included_in_the_list_contract(): void
    {
        $request = ReservationRequest::factory()->create([
            'source' => ReservationSource::Email,
            'raw_payload' => ['from' => 'guest@example.test', 'body' => 'Sensitive plain-text body'],
        ]);

        $payload = $this->toArray($request);

        $this->assertArrayNotHasKey('raw_payload', $payload);
        $this->assertArrayNotHasKey('message', $payload);
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Sensitive plain-text body', $serialized);
    }

    public function test_message_field_is_not_part_of_the_list_contract(): void
    {
        $request = ReservationRequest::factory()->create([
            'message' => 'Allergie: Nüsse',
        ]);

        $payload = $this->toArray($request);

        $this->assertArrayNotHasKey('message', $payload);
    }

    public function test_each_status_enum_value_renders_as_its_string(): void
    {
        foreach (ReservationStatus::cases() as $status) {
            $request = ReservationRequest::factory()->create(['status' => $status]);

            $this->assertSame($status->value, $this->toArray($request)['status']);
        }
    }

    public function test_resource_collection_wraps_each_record_individually(): void
    {
        $records = ReservationRequest::factory()->count(3)->create();

        $payload = ReservationRequestResource::collection($records)
            ->toArray(Request::create('/'));

        $this->assertCount(3, $payload);
        $this->assertSame(
            $records->pluck('id')->all(),
            array_column($payload, 'id'),
        );
    }
}
