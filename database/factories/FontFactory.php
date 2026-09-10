<?php

namespace Database\Factories;

use App\Models\Font;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Font>
 */
class FontFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'original_filename' => fake()->word().'.ttf',
            'storage_path' => 'card-fonts/'.fake()->uuid().'.ttf',
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }
}
