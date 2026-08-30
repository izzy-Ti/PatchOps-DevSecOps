<?php

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Enums\VulnerabilitySource;
use App\Models\Incident;
use App\Models\Vulnerability;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('GET /api/v1/incidents returns paginated incident collection', function () {
    Incident::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/incidents');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'incident_number',
                    'correlation_id',
                    'title',
                    'severity',
                    'priority',
                    'status',
                    'repository',
                    'environment',
                ],
            ],
            'links',
            'meta' => [
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
        ]);

    expect($response->json('meta.total'))->toBe(3);
});

test('GET /api/v1/incidents filters by status, severity, priority, and repository', function () {
    Incident::factory()->create([
        'status' => IncidentStatus::OPEN,
        'severity' => VulnerabilitySeverity::CRITICAL,
        'priority' => IncidentPriority::URGENT,
        'repository' => 'izzy-Ti/patchops-core',
    ]);

    Incident::factory()->create([
        'status' => IncidentStatus::RESOLVED,
        'severity' => VulnerabilitySeverity::LOW,
        'priority' => IncidentPriority::LOW,
        'repository' => 'izzy-Ti/other-app',
    ]);

    // Filter by status
    $statusResponse = $this->getJson('/api/v1/incidents?status=open');
    $statusResponse->assertOk();
    expect($statusResponse->json('meta.total'))->toBe(1);

    // Filter by severity
    $severityResponse = $this->getJson('/api/v1/incidents?severity=critical');
    $severityResponse->assertOk();
    expect($severityResponse->json('meta.total'))->toBe(1);

    // Filter by priority
    $priorityResponse = $this->getJson('/api/v1/incidents?priority=urgent');
    $priorityResponse->assertOk();
    expect($priorityResponse->json('meta.total'))->toBe(1);

    // Filter by repository
    $repoResponse = $this->getJson('/api/v1/incidents?repository=izzy-Ti/patchops-core');
    $repoResponse->assertOk();
    expect($repoResponse->json('meta.total'))->toBe(1);
});

test('GET /api/v1/incidents/{id} returns incident details with nested vulnerability', function () {
    $vulnerability = Vulnerability::factory()->create([
        'source' => VulnerabilitySource::GITHUB,
        'source_id' => 'GHSA-1234',
        'cve_id' => 'CVE-2026-12345',
        'package_name' => 'express',
        'affected_version' => '< 4.19.2',
        'fixed_version' => '4.19.2',
        'reference_url' => 'https://github.com/advisories/GHSA-1234',
    ]);

    $incident = Incident::factory()->create([
        'vulnerability_id' => $vulnerability->id,
        'incident_number' => 'INC-000001',
        'correlation_id' => 'INC-000001',
        'title' => 'CVE-2026-12345',
        'severity' => VulnerabilitySeverity::HIGH,
        'priority' => IncidentPriority::HIGH,
        'status' => IncidentStatus::OPEN,
        'repository' => 'owner/repo',
        'environment' => 'sandbox',
    ]);

    $response = $this->getJson("/api/v1/incidents/{$incident->id}");

    $response->assertOk()
        ->assertJson([
            'data' => [
                'id' => $incident->id,
                'incident_number' => 'INC-000001',
                'correlation_id' => 'INC-000001',
                'title' => 'CVE-2026-12345',
                'severity' => 'high',
                'priority' => 'high',
                'status' => 'open',
                'repository' => 'owner/repo',
                'environment' => 'sandbox',
                'vulnerability' => [
                    'id' => $vulnerability->id,
                    'source' => 'github',
                    'source_id' => 'GHSA-1234',
                    'cve_id' => 'CVE-2026-12345',
                    'package_name' => 'express',
                    'affected_version' => '< 4.19.2',
                    'fixed_version' => '4.19.2',
                    'reference_url' => 'https://github.com/advisories/GHSA-1234',
                ],
            ],
        ]);
});
