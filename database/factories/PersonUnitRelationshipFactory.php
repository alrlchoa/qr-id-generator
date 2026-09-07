<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonUnitRelationship>
 */
class PersonUnitRelationshipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'unit_id' => Unit::factory(),
            'type' => fake()->randomElement(['owner', 'tenant']),
            'is_primary_owner' => false,
            'start_date' => fake()->date(),
            'contract_end_date' => null,
            'ended_at' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => ['ended_at' => now()]);
    }

    public function primaryOwner(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'owner', 'is_primary_owner' => true]);
    }
}
