<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'user_id' => User::factory(),
            'user_role' => 'admin',
            'action' => 'person_created',
            'subject_type' => Person::class,
            'subject_id' => Person::factory(),
            'previous_value' => null,
            'new_value' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function console(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'user_role' => 'console',
            'ip_address' => null,
        ]);
    }
}
