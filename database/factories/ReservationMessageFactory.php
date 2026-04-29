<?php

namespace Database\Factories;

use App\Enums\MessageDirection;
use App\Models\ReservationMessage;
use App\Models\ReservationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReservationMessage>
 */
class ReservationMessageFactory extends Factory
{
    protected $model = ReservationMessage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $direction = fake()->randomElement([MessageDirection::In, MessageDirection::Out]);

        return [
            'reservation_request_id' => ReservationRequest::factory(),
            'direction' => $direction,
            'message_id' => $this->fakeMessageId(),
            'in_reply_to' => null,
            'references' => null,
            'subject' => fake()->sentence(),
            'from_address' => fake()->safeEmail(),
            'to_address' => fake()->safeEmail(),
            'body_plain' => fake()->paragraph(),
            'raw_headers' => 'From: '.fake()->safeEmail()."\nSubject: ".fake()->sentence(),
            'sent_at' => $direction === MessageDirection::Out ? now() : null,
            'received_at' => $direction === MessageDirection::In ? now() : null,
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::In,
            'sent_at' => null,
            'received_at' => now(),
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn () => [
            'direction' => MessageDirection::Out,
            'sent_at' => now(),
            'received_at' => null,
        ]);
    }

    public function forReservationRequest(ReservationRequest $request): static
    {
        return $this->state(fn () => [
            'reservation_request_id' => $request->id,
        ]);
    }

    private function fakeMessageId(): string
    {
        return '<'.Str::lower(Str::random(24)).'@example.test>';
    }
}
