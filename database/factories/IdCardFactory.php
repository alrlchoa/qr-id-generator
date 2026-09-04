<?php

namespace Database\Factories;

use App\Models\IdCard;
use App\Models\Person;
use App\Models\Template;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdCard>
 */
class IdCardFactory extends Factory
{
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'unit_id' => Unit::factory(),
            'control_number' => fake()->unique()->numerify('########'),
            'type' => fake()->randomElement(['owner', 'tenant']),
            'status' => 'active',
            'replacement_reason' => null,
            'replaces_id_card_id' => null,
            'template_id' => Template::factory(),
            'position' => null,
            'department' => null,
            'issued_at' => now(),
        ];
    }

    public function employee(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_id' => null,
            'type' => 'employee',
            'position' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Security', 'Maintenance', 'Administration']),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'revoked']);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'expired']);
    }
}
