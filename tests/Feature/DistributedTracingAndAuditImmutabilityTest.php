<?php

use App\Enums\IncidentStatus;
use App\Enums\RemediationStatus;
use App\Enums\VerificationCheckType;
use App\Exceptions\Observability\ImmutableAuditEventException;
use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Incident;
use App\Models\RemediationRun;
use App\Models\ToolCall;
use App\Models\Trace;
use App\Models\VerificationCheck;
use App\Services\Observability\IncidentTimelineService;
use App\Services\Observability\MetricsService;
use App\Services\Security\SecretRedactionService;
use App\Services\Tracing\TraceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('TraceManager creates distributed trace hierarchy and records redacted tool calls', function () {
    $incident = Incident::factory()->create();
    $manager = app(TraceManager::class);

    $trace = $manager->startTrace($incident);

    expect($trace->id)->toStartWith('trace_')
        ->and($trace->status)->toBe('ACTIVE')
        ->and($manager->currentTraceId())->toBe($trace->id);

    $toolCall = $manager->recordToolCall([
        'incident_id' => $incident->id,
        'tool_name' => 'sandbox.execute',
        'arguments' => [
            'command' => 'npm test',
            'token' => 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        ],
        'result' => ['stdout' => 'Passing', 'secret' => 'supersecret123'],
        'status' => 'SUCCESS',
        'duration_ms' => 120,
    ]);

    expect($toolCall->id)->toStartWith('call_')
        ->and($toolCall->trace_id)->toBe($trace->id)
        ->and($toolCall->arguments['token'])->toBe('[REDACTED_SENSITIVE_VALUE]')
        ->and($toolCall->result['secret'])->toBe('[REDACTED_SENSITIVE_VALUE]');

    $completed = $manager->completeTrace($trace->id);
    expect($completed->status)->toBe('COMPLETED')
        ->and($manager->currentTraceId())->toBeNull();
});

test('AuditEvent model strictly forbids updates and throws ImmutableAuditEventException', function () {
    $incident = Incident::factory()->create();
    $audit = AuditEvent::create([
        'incident_id' => $incident->id,
        'actor_type' => 'system',
        'actor_id' => 'gate_engine',
        'action' => 'quality_gate.evaluated',
        'metadata' => ['score' => 100],
    ]);

    expect(fn () => $audit->update(['action' => 'tampered_action']))
        ->toThrow(ImmutableAuditEventException::class, 'Audit events cannot be modified');
});

test('AuditEvent model strictly forbids deletion and throws ImmutableAuditEventException', function () {
    $incident = Incident::factory()->create();
    $audit = AuditEvent::create([
        'incident_id' => $incident->id,
        'actor_type' => 'system',
        'actor_id' => 'gate_engine',
        'action' => 'quality_gate.evaluated',
        'metadata' => ['score' => 100],
    ]);

    expect(fn () => $audit->delete())
        ->toThrow(ImmutableAuditEventException::class, 'Audit events cannot be deleted');
});

test('SecretRedactionService masks GitHub PATs, AWS keys, Bearer tokens, private keys, and DB credentials', function () {
    $service = app(SecretRedactionService::class);

    $raw = 'Config: token=ghp_1234567890abcdefghijklmnopqrstuvwxyz and key=AKIAIOSFODNN7EXAMPLE '
        .'and auth=Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30.t-ae7H8NWZIOg '
        .'db=postgres://user:supersecretpass@db.internal:5432/main';

    $redacted = $service->redactString($raw);

    expect($redacted)->not->toContain('ghp_1234567890abcdefghijklmnopqrstuvwxyz')
        ->and($redacted)->not->toContain('AKIAIOSFODNN7EXAMPLE')
        ->and($redacted)->not->toContain('supersecretpass')
        ->and($redacted)->toContain('[REDACTED_GITHUB_TOKEN]')
        ->and($redacted)->toContain('[REDACTED_AWS_KEY]')
        ->and($redacted)->toContain('[REDACTED_BEARER_TOKEN]')
        ->and($redacted)->toContain('[REDACTED_PASSWORD]');
});

test('IncidentTimelineService projects chronological timeline of audit events, agent runs, and verification checks', function () {
    $incident = Incident::factory()->create();

    // 1. Audit event
    AuditEvent::create([
        'incident_id' => $incident->id,
        'actor_type' => 'user',
        'actor_id' => '1',
        'action' => 'incident.approved',
        'metadata' => ['note' => 'Approved by security lead'],
        'created_at' => now()->subMinutes(10),
    ]);

    // 2. Agent run
    AgentRun::create([
        'incident_id' => $incident->id,
        'agent_type' => 'patch',
        'status' => 'completed',
        'started_at' => now()->subMinutes(8),
        'completed_at' => now()->subMinutes(7),
        'model' => 'gemini-1.5-pro',
        'created_at' => now()->subMinutes(8),
    ]);

    // 3. Verification check
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::VERIFYING,
    ]);

    VerificationCheck::create([
        'remediation_run_id' => $run->id,
        'check_type' => VerificationCheckType::APPLICATION_HEALTH,
        'status' => 'PASSED',
        'exit_code' => 0,
        'created_at' => now()->subMinutes(5),
    ]);

    $timelineService = app(IncidentTimelineService::class);
    $timeline = $timelineService->buildTimeline($incident);

    expect($timeline)->toBeArray()
        ->and(count($timeline))->toBe(3)
        ->and($timeline[0]['type'])->toBe('AUDIT_EVENT')
        ->and($timeline[1]['type'])->toBe('AGENT_RUN')
        ->and($timeline[2]['type'])->toBe('VERIFICATION_CHECK');
});

test('MetricsService accurately calculates MTTR and operational metrics', function () {
    // Incident 1: remediated in 120 seconds
    $incident1 = Incident::factory()->create([
        'status' => IncidentStatus::REMEDIATED,
        'created_at' => now()->subSeconds(120),
        'remediated_at' => now(),
    ]);

    // Incident 2: remediated in 60 seconds
    $incident2 = Incident::factory()->create([
        'status' => IncidentStatus::REMEDIATED,
        'created_at' => now()->subSeconds(60),
        'remediated_at' => now(),
    ]);

    // Incident 3: still open
    Incident::factory()->create([
        'status' => IncidentStatus::PATCHING,
    ]);

    $metricsService = app(MetricsService::class);
    $metrics = $metricsService->getMetrics();

    expect($metrics['incidents']['total'])->toBe(3)
        ->and($metrics['incidents']['remediated'])->toBe(2)
        ->and($metrics['incidents']['mttr_seconds'])->toBeGreaterThanOrEqual(80.0);
});

test('Observability API endpoints expose timeline, trace, and metrics', function () {
    $incident = Incident::factory()->create();

    $trace = Trace::create([
        'incident_id' => $incident->id,
        'correlation_id' => $incident->correlation_id,
        'status' => 'ACTIVE',
    ]);

    ToolCall::create([
        'incident_id' => $incident->id,
        'trace_id' => $trace->id,
        'tool_name' => 'cve.lookup',
        'status' => 'SUCCESS',
    ]);

    // 1. Timeline endpoint
    $responseTimeline = $this->getJson("/api/v1/incidents/{$incident->id}/timeline");
    $responseTimeline->assertOk()
        ->assertJsonStructure(['incident_id', 'total_events', 'data']);

    // 2. Trace endpoint
    $responseTrace = $this->getJson("/api/v1/incidents/{$incident->id}/trace");
    $responseTrace->assertOk()
        ->assertJsonStructure(['incident_id', 'total_traces', 'data']);

    // 3. Metrics endpoint
    $responseMetrics = $this->getJson('/api/v1/metrics');
    $responseMetrics->assertOk()
        ->assertJsonStructure(['data' => ['incidents', 'agent_performance', 'verification_checks']]);
});
