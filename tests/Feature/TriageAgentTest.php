<?php

use App\Agents\TriageAgent;
use App\DTOs\AgentResultDTO;
use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Jobs\ReproduceIncidentJob;
use App\Jobs\TriageIncidentJob;
use App\Models\Incident;
use App\Models\Vulnerability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('AgentResultDTO creates standardized success and failure envelopes', function () {
    $success = AgentResultDTO::success(
        data: ['severity' => 'critical', 'priority' => 'critical'],
        metadata: ['agent' => 'TriageAgent', 'execution_time_seconds' => 0.45],
    );

    expect($success->success)->toBeTrue()
        ->and($success->status)->toBe('completed')
        ->and($success->data['severity'])->toBe('critical')
        ->and($success->error)->toBeNull()
        ->and($success->metadata['agent'])->toBe('TriageAgent');

    $failure = AgentResultDTO::failure(
        code: 'SCHEMA_VALIDATION_FAILED',
        message: 'Invalid payload',
        details: ['field' => 'severity'],
        metadata: ['agent' => 'TriageAgent'],
    );

    expect($failure->success)->toBeFalse()
        ->and($failure->status)->toBe('failed')
        ->and($failure->error?->code)->toBe('SCHEMA_VALIDATION_FAILED')
        ->and($failure->error?->message)->toBe('Invalid payload');
});

test('TriageAgent analyzes incident and returns structured AgentResultDTO via Claude tool calling', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_123',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_01',
                    'name' => 'record_triage_analysis',
                    'input' => [
                        'severity' => 'critical',
                        'priority' => 'critical',
                        'production_exposed' => true,
                        'affected_component' => 'symfony/http-foundation',
                        'reason' => 'Direct user input passed to header parsing enables Remote Code Execution in production runtime.',
                    ],
                ],
            ],
        ], 200),
    ]);

    $vuln = Vulnerability::factory()->create([
        'package_name' => 'symfony/http-foundation',
        'cve_id' => 'CVE-2026-9999',
    ]);

    $incident = Incident::factory()->create([
        'vulnerability_id' => $vuln->id,
        'status' => IncidentStatus::RECEIVED,
    ]);

    $agent = new TriageAgent;
    $result = $agent->analyze($incident);

    expect($result)->toBeInstanceOf(AgentResultDTO::class)
        ->and($result->success)->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and($result->data['severity'])->toBe('critical')
        ->and($result->data['priority'])->toBe('critical')
        ->and($result->data['production_exposed'])->toBeTrue()
        ->and($result->data['affected_component'])->toBe('symfony/http-foundation')
        ->and($result->data['reason'])->toContain('Remote Code Execution');
});

test('TriageAgent gracefully handles Claude API non-transient error', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'Invalid request payload'],
        ], 400),
    ]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    $agent = new TriageAgent;
    $result = $agent->analyze($incident);

    expect($result->success)->toBeFalse()
        ->and($result->error?->message)->toContain('Anthropic API error: 400');
});

test('TriageIncidentJob executes full triage flow from RECEIVED to PRIORITIZED and dispatches reproduction job', function () {
    Queue::fake([ReproduceIncidentJob::class]);
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_02',
                    'name' => 'record_triage_analysis',
                    'input' => [
                        'severity' => 'critical',
                        'priority' => 'critical',
                        'production_exposed' => true,
                        'affected_component' => 'vendor/core-auth',
                        'reason' => 'Critical auth bypass in production middleware.',
                    ],
                ],
            ],
        ], 200),
    ]);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::RECEIVED,
        'severity' => VulnerabilitySeverity::LOW,
        'priority' => IncidentPriority::LOW,
    ]);

    $job = new TriageIncidentJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::PRIORITIZED)
        ->and($incident->severity)->toBe(VulnerabilitySeverity::CRITICAL)
        ->and($incident->priority)->toBe(IncidentPriority::URGENT)
        ->and($incident->metadata['production_exposed'])->toBeTrue()
        ->and($incident->metadata['affected_component'])->toBe('vendor/core-auth');

    Queue::assertPushed(ReproduceIncidentJob::class, function ($job) use ($incident) {
        return $job->incident->id === $incident->id;
    });
});

test('TriageIncidentJob escalates incident when triage fails', function () {
    Queue::fake([ReproduceIncidentJob::class]);
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response(['error' => 'Invalid Schema'], 400),
    ]);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::RECEIVED,
    ]);

    $job = new TriageIncidentJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::ESCALATED)
        ->and($incident->metadata['error_history'])->toHaveCount(1);
    Queue::assertNotPushed(ReproduceIncidentJob::class);
});
