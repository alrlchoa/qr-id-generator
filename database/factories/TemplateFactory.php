<?php

namespace Database\Factories;

use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id_type' => fake()->randomElement(['owner', 'tenant', 'employee']),
            'name' => fake()->words(3, true),
            'background_path' => 'templates/'.fake()->uuid().'.png',
            'width_px' => 1013,
            'height_px' => 638,
            'field_positions' => [
                'photo' => ['x' => 40, 'y' => 40, 'width' => 200, 'height' => 200],
                'first_name' => ['x' => 260, 'y' => 60, 'width' => 400, 'height' => 40],
            ],
            'is_active' => false,
        ];
    }
}
