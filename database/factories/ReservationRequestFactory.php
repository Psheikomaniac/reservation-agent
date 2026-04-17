<?php

namespace Database\Factories;

use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Models\ReservationRequest;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationRequest>
 */
class ReservationRequestFactory extends Factory
{
    protected $model = ReservationRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'source' => ReservationSource::WebForm,
            'status' => ReservationStatus::New,
            'guest_name' => fake()->name(),
            'guest_email' => fake()->safeEmail(),
            'guest_phone' => fake()->optional()->e164PhoneNumber(),
            'party_size' => fake()->numberBetween(2, 8),
            'desired_at' => fake()->dateTimeBetween('+1 day', '+14 days'),
            'message' => fake()->optional()->sentence(),
            'raw_payload' => null,
            'needs_manual_review' => false,
        ];
    }

    public function inReview(): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::InReview,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Confirmed,
        ]);
    }

    public function forRestaurant(Restaurant $restaurant): static
    {
        return $this->state(fn () => [
            'restaurant_id' => $restaurant->id,
        ]);
    }
}
