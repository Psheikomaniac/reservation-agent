<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\Table;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Table>
 */
class TableFactory extends Factory
{
    protected $model = Table::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'label' => fake()->unique()->numerify('Tisch ##'),
            'seats' => fake()->numberBetween(2, 8),
            'room_tag' => fake()->randomElement([null, 'Innen', 'Terrasse']),
            'sort_order' => fake()->numberBetween(1, 100),
            'active' => true,
            'combinable_with' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
