<?php

namespace Database\Factories;

use App\Enums\ReservationReplyStatus;
use App\Models\ReservationReply;
use App\Models\ReservationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationReply>
 */
class ReservationReplyFactory extends Factory
{
    protected $model = ReservationReply::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_request_id' => ReservationRequest::factory(),
            'status' => ReservationReplyStatus::Draft,
            'body' => fake()->paragraph(),
            'ai_prompt_snapshot' => null,
            'approved_by' => null,
            'approved_at' => null,
            'sent_at' => null,
            'error_message' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => ReservationReplyStatus::Draft,
            'approved_by' => null,
            'approved_at' => null,
            'sent_at' => null,
            'error_message' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => ReservationReplyStatus::Approved,
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'sent_at' => null,
            'error_message' => null,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => ReservationReplyStatus::Sent,
            'approved_by' => User::factory(),
            'approved_at' => now()->subMinutes(2),
            'sent_at' => now(),
            'error_message' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ReservationReplyStatus::Failed,
            'approved_by' => User::factory(),
            'approved_at' => now()->subMinutes(5),
            'sent_at' => null,
            'error_message' => 'SMTP connection timed out after 30s',
        ]);
    }

    public function forReservationRequest(ReservationRequest $request): static
    {
        return $this->state(fn () => [
            'reservation_request_id' => $request->id,
        ]);
    }
}
