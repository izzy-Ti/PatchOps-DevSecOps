<?php

namespace Database\Factories;

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Models\Incident;
use App\Models\User;
use App\Models\Vulnerability;
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
            'vulnerability_id' => Vulnerability::factory(),
            'correlation_id' => 'INC-'.strtoupper(Str::random(8)),
            'incident_number' => 'INC-'.fake()->numberBetween(2023, 2026).'-'.strtoupper(Str::random(6)),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'severity' => fake()->randomElement(VulnerabilitySeverity::cases()),
            'priority' => fake()->randomElement(IncidentPriority::cases()),
            'status' => IncidentStatus::RECEIVED,
            'repository' => 'izzy-Ti/'.fake()->slug(2),
            'environment' => 'sandbox',
            'root_cause' => fake()->sentence(8),
            'assigned_agent' => fake()->randomElement(['TriageAgent', 'PatchSynthesisAgent', 'SandboxVerifierAgent']),
            'metadata' => ['source' => 'api_test'],
            'user_id' => User::factory(),
            'resolved_at' => null,
        ];
    }
}
