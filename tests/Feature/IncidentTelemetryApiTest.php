<?php

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Models\AgentRun;
use App\Models\Incident;
use App\Models\IncidentTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('GET /api/v1/incidents/{incident} returns incident metadata and resolves by ID or incident_number', function () {
    $incident = Incident::factory()->create([
        'incident_number' => 'INC-TEST-001',
        'title' => 'SQL injection in payment gateway',
        'severity' => VulnerabilitySeverity::CRITICAL,
        'priority' => IncidentPriority::URGENT,
        'status' => IncidentStatus::VALIDATING,
        'repository' => 'org/billing-service',
    ]);

    // Resolve by numeric ID
    $responseById = $this->getJson("/api/v1/incidents/{$incident->id}");
    $responseById->assertOk()
        ->assertJsonPath('data.id', $incident->id)
        ->assertJsonPath('data.incident_number', 'INC-TEST-001')
        ->assertJsonPath('data.severity', 'critical')
        ->assertJsonPath('data.priority', 'urgent')
        ->assertJsonPath('data.status', 'validating');

    // Resolve by string incident_number
    $responseByNumber = $this->getJson('/api/v1/incidents/INC-TEST-001');
    $responseByNumber->assertOk()
        ->assertJsonPath('data.id', $incident->id)
        ->assertJsonPath('data.title', 'SQL injection in payment gateway');

    // Non-existent returns 404
    $this->getJson('/api/v1/incidents/NON-EXISTENT')->assertNotFound();
});

test('GET /api/v1/incidents/{incident}/agent-runs returns chronological execution records', function () {
    $incident = Incident::factory()->create(['incident_number' => 'INC-RUNS-001']);

    AgentRun::create([
        'incident_id' => $incident->id,
        'agent_type' => 'triage',
        'status' => 'completed',
        'attempt' => 1,
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(9),
        'duration' => 1.25,
        'output' => ['severity' => 'critical'],
    ]);

    AgentRun::create([
        'incident_id' => $incident->id,
        'agent_type' => 'patch',
        'status' => 'failed',
        'attempt' => 1,
        'started_at' => now()->subMinutes(5),
        'completed_at' => now()->subMinutes(4),
        'duration' => 2.50,
        'error' => ['code' => 'PATCH_SYNTHESIS_FAILED', 'message' => 'Diff parsing error'],
    ]);

    $response = $this->getJson("/api/v1/incidents/{$incident->id}/agent-runs");

    $response->assertOk()
        ->assertJsonPath('incident_id', $incident->id)
        ->assertJsonPath('incident_number', 'INC-RUNS-001')
        ->assertJsonPath('total_runs', 2)
        ->assertJsonPath('data.0.agent_type', 'triage')
        ->assertJsonPath('data.0.status', 'completed')
        ->assertJsonPath('data.1.agent_type', 'patch')
        ->assertJsonPath('data.1.status', 'failed')
        ->assertJsonPath('data.1.error.code', 'PATCH_SYNTHESIS_FAILED');

    // Also resolve via incident_number
    $this->getJson('/api/v1/incidents/INC-RUNS-001/agent-runs')
        ->assertOk()
        ->assertJsonPath('total_runs', 2);
});

test('GET /api/v1/incidents/{incident}/transitions returns chronological state transitions', function () {
    $incident = Incident::factory()->create([
        'incident_number' => 'INC-TRANS-001',
        'status' => IncidentStatus::REPRODUCED,
    ]);

    IncidentTransition::create([
        'incident_id' => $incident->id,
        'from_status' => IncidentStatus::RECEIVED,
        'to_status' => IncidentStatus::TRIAGING,
        'reason' => 'Triage worker initiated',
        'actor_type' => 'agent',
        'actor_id' => 'triage-agent',
        'correlation_id' => 'corr-test-123',
        'created_at' => now()->subMinutes(15),
    ]);

    IncidentTransition::create([
        'incident_id' => $incident->id,
        'from_status' => IncidentStatus::TRIAGING,
        'to_status' => IncidentStatus::PRIORITIZED,
        'reason' => 'Triage analysis completed',
        'actor_type' => 'agent',
        'actor_id' => 'triage-agent',
        'correlation_id' => 'corr-test-123',
        'created_at' => now()->subMinutes(10),
    ]);

    $response = $this->getJson("/api/v1/incidents/{$incident->id}/transitions");

    $response->assertOk()
        ->assertJsonPath('incident_id', $incident->id)
        ->assertJsonPath('incident_number', 'INC-TRANS-001')
        ->assertJsonPath('current_status', 'reproduced')
        ->assertJsonPath('total_transitions', 2)
        ->assertJsonPath('data.0.from_status', 'received')
        ->assertJsonPath('data.0.to_status', 'triaging')
        ->assertJsonPath('data.1.from_status', 'triaging')
        ->assertJsonPath('data.1.to_status', 'prioritized');

    // Also resolve via incident_number
    $this->getJson('/api/v1/incidents/INC-TRANS-001/transitions')
        ->assertOk()
        ->assertJsonPath('total_transitions', 2);
});
