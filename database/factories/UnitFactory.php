<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'building' => fake()->randomElement(['Tower A', 'Tower B', 'Tower C']),
            'tower' => fake()->randomElement(['North', 'South']),
            'floor' => (string) fake()->numberBetween(1, 40),
            'unit_number' => fake()->unique()->numerify('##-##'),
        ];
    }
}
