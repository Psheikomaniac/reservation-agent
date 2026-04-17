<?php

namespace Database\Factories;

use App\Models\FailedEmailImport;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FailedEmailImport>
 */
class FailedEmailImportFactory extends Factory
{
    protected $model = FailedEmailImport::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $messageId = '<'.Str::uuid()->toString().'@mail.example.com>';
        $from = fake()->safeEmail();
        $subject = 'Reservierung '.fake()->date();

        $rawHeaders = implode("\n", [
            "From: {$from}",
            'To: reservierung@restaurant.example',
            "Subject: {$subject}",
            'Date: '.now()->toRfc2822String(),
            "Message-ID: {$messageId}",
            'Content-Type: text/plain; charset=UTF-8',
        ]);

        return [
            'restaurant_id' => Restaurant::factory(),
            'message_id' => $messageId,
            'raw_headers' => $rawHeaders,
            'raw_body' => fake()->paragraph(3),
            'error' => 'Unable to parse desired_at: ambiguous date phrase "nächsten Freitag".',
        ];
    }

    public function forRestaurant(Restaurant $restaurant): static
    {
        return $this->state(fn () => [
            'restaurant_id' => $restaurant->id,
        ]);
    }
}
