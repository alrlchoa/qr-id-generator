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
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->lastName(),
            'last_name' => fake()->lastName(),
            'suffix' => null,
            'photo_path' => null,
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
}
