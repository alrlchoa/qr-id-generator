<?php

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id_number' => fake()->unique()->numerify('########'),
            'entity_type' => 'natural',
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->lastName(),
            'last_name' => fake()->lastName(),
            'suffix' => null,
            'legal_name' => null,
            'photo_path' => fake()->uuid().'.jpg',
            'date_of_birth' => fake()->date(),
            'place_of_birth' => fake()->city(),
            'gender' => fake()->randomElement(['male', 'female', 'prefer_not_to_say']),
            'home_address' => fake()->address(),
            'mobile_number' => fake()->phoneNumber(),
            'landline_number' => null,
            'email' => fake()->safeEmail(),
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_number' => fake()->phoneNumber(),
            'emergency_contact_relation' => fake()->randomElement(['Spouse', 'Parent', 'Sibling', 'Friend']),
            'notes' => null,
        ];
    }

    /**
     * A company party: legal_name only, every person-name column null, no
     * photo — never cardable (architecture §3, CLAUDE.md rule 36).
     */
    public function company(): static
    {
        return $this->state(fn () => [
            'entity_type' => 'company',
            'first_name' => null,
            'middle_name' => null,
            'last_name' => null,
            'suffix' => null,
            'legal_name' => fake()->company(),
            'photo_path' => null,
        ]);
    }

    /**
     * The Minimal tier only — a name for the kind and nothing else. A
     * finished, valid record, not a draft (CLAUDE.md rule 33).
     */
    public function minimal(): static
    {
        return $this->state(fn () => [
            'photo_path' => null,
            'date_of_birth' => null,
            'place_of_birth' => null,
            'gender' => null,
            'home_address' => null,
            'mobile_number' => null,
            'landline_number' => null,
            'email' => null,
            'emergency_contact_name' => null,
            'emergency_contact_number' => null,
            'emergency_contact_relation' => null,
            'notes' => null,
        ]);
    }
}
