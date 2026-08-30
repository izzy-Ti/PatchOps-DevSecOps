<?php

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\AgentRun;
use App\Models\Incident;
use App\Models\IncidentTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('1. GET /api/health returns 200 OK', function () {
    $response = $this->getJson('/api/health');
    $response->assertOk();
    dump(['route' => 'GET /api/health', 'status' => $response->status(), 'response' => $response->json()]);
});

test('2. POST /api/auth/register returns 201 Created', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'SecOps Tester',
        'email' => 'secops@patchops.dev',
        'password' => 'SecurePassword123!',
        'password_confirmation' => 'SecurePassword123!',
    ]);
    $response->assertCreated();
    dump(['route' => 'POST /api/auth/register', 'status' => $response->status(), 'response' => $response->json()]);
});

test('3. POST /api/auth/login returns 200 OK with token', function () {
    User::factory()->create([
        'email' => 'login@patchops.dev',
        'password' => bcrypt('SecurePassword123!'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'login@patchops.dev',
        'password' => 'SecurePassword123!',
    ]);
    $response->assertOk();
    dump(['route' => 'POST /api/auth/login', 'status' => $response->status(), 'token_present' => ! empty($response->json('data.token'))]);
});

test('4. POST /api/auth/logout returns 200 OK', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/auth/logout');
    $response->assertOk();
    dump(['route' => 'POST /api/auth/logout', 'status' => $response->status(), 'response' => $response->json()]);
});

test('5. POST /api/v1/webhooks/github returns 200 OK', function () {
    config()->set('services.github.webhook_secret', 'test-secret');

    $payload = [
        'action' => 'created',
        'repository' => ['full_name' => 'org/repo'],
        'alert' => [
            'number' => 101,
            'security_advisory' => [
                'cve_id' => 'CVE-2026-0001',
                'summary' => 'RCE in package',
                'description' => 'Test vulnerability',
                'severity' => 'critical',
                'vulnerabilities' => [
                    [
                        'package' => ['name' => 'vendor/pkg'],
                        'vulnerable_version_range' => '< 1.2.0',
                        'first_patched_version' => ['identifier' => '1.2.0'],
                    ],
                ],
            ],
            'html_url' => 'https://github.com/org/repo/security/advisories/GHSA-123',
        ],
    ];

    $content = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $content, 'test-secret');

    $response = $this->call('POST', '/api/v1/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'HTTP_X_GITHUB_EVENT' => 'dependabot_alert',
    ], $content);

    $response->assertCreated();
    dump(['route' => 'POST /api/v1/webhooks/github', 'status' => $response->status(), 'response' => $response->json()]);
});

test('6. POST /api/v1/webhooks/snyk returns 201 Created', function () {
    config()->set('services.snyk.webhook_secret', 'test-secret');

    $payload = [
        'project' => [
            'name' => 'org/repo:composer.json',
            'browseUrl' => 'https://app.snyk.io/org/repo',
        ],
        'vulnerability' => [
            'id' => 'SNYK-PHP-PACKAGE-12345',
            'title' => 'Prototype pollution in snyk package',
            'description' => 'Detailed snyk flaw description',
            'severity' => 'high',
            'packageName' => 'vendor/pkg',
            'version' => '1.0.0',
            'identifiers' => [
                'CVE' => ['CVE-2026-9988'],
            ],
            'semver' => [
                'vulnerable' => ['< 1.1.0'],
            ],
            'fixedIn' => ['1.1.0'],
            'url' => 'https://snyk.io/vuln/SNYK-123',
        ],
    ];

    $content = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $content, 'test-secret');

    $response = $this->call('POST', '/api/v1/webhooks/snyk', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'HTTP_X_SNYK_EVENT' => 'wlc-notification',
    ], $content);

    $response->assertCreated();
    dump(['route' => 'POST /api/v1/webhooks/snyk', 'status' => $response->status(), 'response' => $response->json()]);
});

test('7. POST /api/v1/webhooks/cve returns 201 Created', function () {
    $payload = [
        'cve' => [
            'id' => 'CVE-2026-7777',
            'sourceIdentifier' => 'cve@mitre.org',
            'descriptions' => [
                ['lang' => 'en', 'value' => 'SQL injection flaw'],
            ],
            'metrics' => [
                'cvssMetricV31' => [
                    ['cvssData' => ['baseSeverity' => 'HIGH']],
                ],
            ],
            'configurations' => [
                [
                    'nodes' => [
                        [
                            'cpeMatch' => [
                                [
                                    'criteria' => 'cpe:2.3:a:laravel:framework:*:*:*:*:*:*:*:*',
                                    'versionStartIncluding' => '10.0.0',
                                    'versionEndExcluding' => '10.48.0',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'references' => [
                ['url' => 'https://nvd.nist.gov/vuln/detail/CVE-2026-7777'],
            ],
        ],
    ];

    $response = $this->postJson('/api/v1/webhooks/cve', $payload);
    $response->assertCreated();
    dump(['route' => 'POST /api/v1/webhooks/cve', 'status' => $response->status(), 'response' => $response->json()]);
});

test('8. GET /api/v1/incidents returns 200 OK', function () {
    Incident::factory()->count(3)->create();

    $response = $this->getJson('/api/v1/incidents');
    $response->assertOk();
    dump(['route' => 'GET /api/v1/incidents', 'status' => $response->status(), 'count' => count($response->json('data'))]);
});

test('9. GET /api/v1/incidents/{incident} returns 200 OK', function () {
    $incident = Incident::factory()->create([
        'incident_number' => 'INC-VERIFY-001',
        'title' => 'Authentication bypass',
    ]);

    $response = $this->getJson("/api/v1/incidents/{$incident->id}");
    $response->assertOk();
    dump(['route' => 'GET /api/v1/incidents/{incident}', 'status' => $response->status(), 'incident_number' => $response->json('data.incident_number')]);
});

test('10. GET /api/v1/incidents/{incident}/agent-runs returns 200 OK', function () {
    $incident = Incident::factory()->create(['incident_number' => 'INC-VERIFY-002']);
    AgentRun::create([
        'incident_id' => $incident->id,
        'agent_type' => 'triage',
        'status' => 'completed',
        'attempt' => 1,
        'started_at' => now(),
        'completed_at' => now(),
        'duration' => 0.85,
        'output' => ['severity' => 'critical'],
    ]);

    $response = $this->getJson("/api/v1/incidents/{$incident->id}/agent-runs");
    $response->assertOk();
    dump(['route' => 'GET /api/v1/incidents/{incident}/agent-runs', 'status' => $response->status(), 'total_runs' => $response->json('total_runs')]);
});

test('11. GET /api/v1/incidents/{incident}/transitions returns 200 OK', function () {
    $incident = Incident::factory()->create([
        'incident_number' => 'INC-VERIFY-003',
        'status' => IncidentStatus::TRIAGING,
    ]);

    IncidentTransition::create([
        'incident_id' => $incident->id,
        'from_status' => IncidentStatus::RECEIVED,
        'to_status' => IncidentStatus::TRIAGING,
        'reason' => 'Triage initiated',
        'actor_type' => 'agent',
        'actor_id' => 'triage-agent',
        'correlation_id' => 'corr-verify-123',
        'created_at' => now(),
    ]);

    $response = $this->getJson("/api/v1/incidents/{$incident->id}/transitions");
    $response->assertOk();
    dump(['route' => 'GET /api/v1/incidents/{incident}/transitions', 'status' => $response->status(), 'total_transitions' => $response->json('total_transitions')]);
});

test('12. GET /api/incidents (Protected) returns 200 OK', function () {
    $user = User::factory()->create(['role' => UserRole::ADMIN]);
    Incident::factory()->count(2)->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/incidents');
    $response->assertOk();
    dump(['route' => 'GET /api/incidents', 'status' => $response->status(), 'count' => count($response->json('data'))]);
});

test('13. POST /api/incidents (Protected) returns 201 Created', function () {
    $user = User::factory()->create(['role' => UserRole::ADMIN]);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/incidents', [
        'title' => 'Zero-day vulnerability in API Gateway',
        'description' => 'Discovered unauthenticated path traversal',
        'severity' => 'critical',
        'metadata' => ['service' => 'api-gateway'],
    ]);

    $response->assertCreated();
    dump(['route' => 'POST /api/incidents', 'status' => $response->status(), 'incident_id' => $response->json('data.id')]);
});

test('14. GET /api/incidents/{incident} (Protected) returns 200 OK', function () {
    $user = User::factory()->create(['role' => UserRole::ADMIN]);
    $incident = Incident::factory()->create(['title' => 'Remote Code Execution in worker']);

    $response = $this->actingAs($user, 'sanctum')->getJson("/api/incidents/{$incident->id}");
    $response->assertOk();
    dump(['route' => 'GET /api/incidents/{incident}', 'status' => $response->status(), 'title' => $response->json('data.title')]);
});
