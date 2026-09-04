<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * ABBCC: building_code (nullable letter), floor_code (2 alphanumeric,
     * sometimes given as a single character to exercise the model's
     * left-pad-with-zero mutator), unit_number (2 digits).
     *
     * unit_number alone is drawn from fake()->unique(), which is enough to
     * make the (building_code, floor_code, unit_number) tuple unique too.
     */
    public function definition(): array
    {
        return [
            'building_code' => fake()->optional(0.6)->randomLetter(),
            'floor_code' => fake()->randomElement(['G', 'M', 'LG', fake()->numerify('##')]),
            'unit_number' => fake()->unique()->numerify('##'),
        ];
    }
}
