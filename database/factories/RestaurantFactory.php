<?php

namespace Database\Factories;

use App\Enums\Tonality;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'timezone' => 'Europe/Berlin',
            'capacity' => fake()->numberBetween(20, 80),
            'opening_hours' => [
                'mon' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
                'tue' => [],
                'wed' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
                'thu' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '22:30']],
                'fri' => [['from' => '11:30', 'to' => '14:30'], ['from' => '18:00', 'to' => '23:00']],
                'sat' => [['from' => '18:00', 'to' => '23:00']],
                'sun' => [['from' => '11:30', 'to' => '15:00']],
            ],
            'tonality' => fake()->randomElement(Tonality::cases()),
            'imap_host' => null,
            'imap_username' => null,
            'imap_password' => null,
        ];
    }
}
