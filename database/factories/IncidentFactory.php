<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'correlation_id' => 'INC-'.strtoupper(Str::random(8)),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'status' => 'open',
            'metadata' => ['source' => 'api_test'],
            'user_id' => User::factory(),
        ];
    }
}
