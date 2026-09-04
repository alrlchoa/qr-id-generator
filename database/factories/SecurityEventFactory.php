<?php

namespace Database\Factories;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'user_id' => User::factory(),
            'event_type' => 'login_failed',
            'detail' => ['reason' => 'invalid_credentials'],
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function unauthenticated(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null]);
    }
}
