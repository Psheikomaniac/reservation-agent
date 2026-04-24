<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\DashboardFilterRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DashboardFilterRequestTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, (new DashboardFilterRequest)->rules());
    }

    public function test_empty_payload_is_valid(): void
    {
        $this->assertTrue($this->validate([])->passes());
    }

    public function test_accepts_every_reservation_status(): void
    {
        $validator = $this->validate([
            'status' => ['new', 'in_review', 'replied', 'confirmed', 'declined', 'cancelled'],
        ]);

        $this->assertTrue($validator->passes(), json_encode($validator->errors()->all()));
    }

    public function test_rejects_unknown_status_value(): void
    {
        $validator = $this->validate(['status' => ['new', 'bogus']]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status.1', $validator->errors()->toArray());
    }

    public function test_status_must_be_an_array(): void
    {
        $validator = $this->validate(['status' => 'new']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    public function test_accepts_every_reservation_source(): void
    {
        $validator = $this->validate(['source' => ['web_form', 'email']]);

        $this->assertTrue($validator->passes(), json_encode($validator->errors()->all()));
    }

    public function test_rejects_unknown_source_value(): void
    {
        $validator = $this->validate(['source' => ['carrier_pigeon']]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('source.0', $validator->errors()->toArray());
    }

    public function test_source_must_be_an_array(): void
    {
        $validator = $this->validate(['source' => 'email']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('source', $validator->errors()->toArray());
    }

    public function test_from_and_to_are_nullable(): void
    {
        $this->assertTrue($this->validate(['from' => null, 'to' => null])->passes());
    }

    public function test_from_must_be_a_valid_date(): void
    {
        $validator = $this->validate(['from' => 'not-a-date']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('from', $validator->errors()->toArray());
    }

    public function test_to_must_be_after_or_equal_to_from(): void
    {
        $validator = $this->validate(['from' => '2026-05-10', 'to' => '2026-05-09']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('to', $validator->errors()->toArray());
    }

    public function test_to_equal_to_from_is_allowed(): void
    {
        $this->assertTrue(
            $this->validate(['from' => '2026-05-10', 'to' => '2026-05-10'])->passes(),
        );
    }

    public function test_to_after_from_is_allowed(): void
    {
        $this->assertTrue(
            $this->validate(['from' => '2026-05-10', 'to' => '2026-05-11'])->passes(),
        );
    }

    public function test_q_is_nullable(): void
    {
        $this->assertTrue($this->validate(['q' => null])->passes());
    }

    public function test_q_accepts_search_string(): void
    {
        $this->assertTrue($this->validate(['q' => 'Müller'])->passes());
    }

    public function test_q_is_capped_at_120_characters(): void
    {
        $validator = $this->validate(['q' => str_repeat('a', 121)]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('q', $validator->errors()->toArray());
    }

    public function test_q_at_exactly_120_characters_is_allowed(): void
    {
        $this->assertTrue($this->validate(['q' => str_repeat('a', 120)])->passes());
    }

    public function test_validated_payload_drops_unknown_keys(): void
    {
        $validator = $this->validate([
            'status' => ['new'],
            'malicious_key' => 'value',
            'restaurant_id' => 999,
        ]);

        $this->assertTrue($validator->passes());

        $validated = $validator->validated();
        $this->assertArrayHasKey('status', $validated);
        $this->assertArrayNotHasKey('malicious_key', $validated);
        $this->assertArrayNotHasKey('restaurant_id', $validated);
    }
}
