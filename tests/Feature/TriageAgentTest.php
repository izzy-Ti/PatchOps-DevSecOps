<?php

use App\Agents\TriageAgent;
use App\DTOs\TriageResultDTO;
use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Jobs\ReproduceVulnerabilityJob;
use App\Jobs\TriageIncidentJob;
use App\Models\Incident;
use App\Models\Vulnerability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('TriageResultDTO validates correct and incomplete payloads', function () {
    $valid = TriageResultDTO::success([
        'severity' => 'critical',
        'priority' => 'critical',
        'production_exposed' => true,
        'affected_component' => 'express/router',
        'reason' => 'RCE via prototype pollution in production API router',
    ]);

    expect($valid->isValid())->toBeTrue();

    $invalidSeverity = TriageResultDTO::success([
        'severity' => 'unknown_severity',
        'priority' => 'critical',
        'production_exposed' => true,
        'affected_component' => 'express/router',
        'reason' => 'Reason',
    ]);
    expect($invalidSeverity->isValid())->toBeFalse();

    $missingField = TriageResultDTO::success([
        'severity' => 'high',
        'priority' => 'high',
        'production_exposed' => null,
        'affected_component' => '',
        'reason' => 'Reason',
    ]);
    expect($missingField->isValid())->toBeFalse();

    $failed = TriageResultDTO::failure('Network timeout');
    expect($failed->isValid())->toBeFalse()
        ->and($failed->errorMessage)->toBe('Network timeout');
});

test('TriageAgent analyzes incident and returns structured TriageResultDTO via Claude tool calling', function () {
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

    expect($result)->toBeInstanceOf(TriageResultDTO::class)
        ->and($result->isValid())->toBeTrue()
        ->and($result->severity)->toBe('critical')
        ->and($result->priority)->toBe('critical')
        ->and($result->productionExposed)->toBeTrue()
        ->and($result->affectedComponent)->toBe('symfony/http-foundation')
        ->and($result->reason)->toContain('Remote Code Execution');
});

test('TriageAgent gracefully handles Claude API error', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit exceeded'],
        ], 429),
    ]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    $agent = new TriageAgent;
    $result = $agent->analyze($incident);

    expect($result->isValid())->toBeFalse()
        ->and($result->errorMessage)->toContain('Anthropic API error: 429');
});

test('TriageIncidentJob executes full triage flow from RECEIVED to PRIORITIZED and dispatches reproduction job', function () {
    Queue::fake([ReproduceVulnerabilityJob::class]);
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

    Queue::assertPushed(ReproduceVulnerabilityJob::class, function ($job) use ($incident) {
        return $job->incident->id === $incident->id;
    });
});

test('TriageIncidentJob escalates incident when triage fails', function () {
    Queue::fake([ReproduceVulnerabilityJob::class]);
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response(['error' => 'API Unavailable'], 500),
    ]);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::RECEIVED,
    ]);

    $job = new TriageIncidentJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::ESCALATED);
    Queue::assertNotPushed(ReproduceVulnerabilityJob::class);
});
