<?php

use App\DTOs\AgentErrorDTO;
use App\DTOs\AgentResultDTO;
use App\Enums\IncidentStatus;
use App\Jobs\TriageIncidentJob;
use App\Jobs\ValidatePatchJob;
use App\Models\AgentRun;
use App\Models\Incident;
use App\Services\AgentRunTracker;
use App\Services\Sandbox\ProcessResult;
use App\Services\Sandbox\SandboxManagerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config()->set('services.anthropic.key', null);
});

test('AgentRunTracker tracks the start, complete, and fail lifecycle of an agent run', function () {
    $incident = Incident::factory()->create(['correlation_id' => 'corr-12345']);
    $tracker = app(AgentRunTracker::class);

    // 1. Start run
    $run = $tracker->start($incident, 'triage', 1, ['input_key' => 'input_val']);

    expect($run)->toBeInstanceOf(AgentRun::class)
        ->and($run->incident_id)->toBe($incident->id)
        ->and($run->agent_type)->toBe('triage')
        ->and($run->status)->toBe('running')
        ->and($run->attempt)->toBe(1)
        ->and($run->correlation_id)->toBe('corr-12345')
        ->and($run->input_context)->toBe(['input_key' => 'input_val'])
        ->and($run->started_at)->not->toBeNull()
        ->and($run->completed_at)->toBeNull();

    // 2. Complete run
    $successResult = AgentResultDTO::success(['assessed_severity' => 'high']);
    $tracker->complete($run, $successResult);

    $run->refresh();
    expect($run->status)->toBe('completed')
        ->and($run->output)->toBe(['assessed_severity' => 'high'])
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->duration)->toBeGreaterThanOrEqual(0);

    // 3. Fail run
    $run2 = $tracker->start($incident, 'reproduction', 1);
    $failResult = AgentResultDTO::failure(AgentErrorDTO::REPRODUCTION_FAILED, 'Vulnerability not triggered');
    $tracker->fail($run2, $failResult);

    $run2->refresh();
    expect($run2->status)->toBe('failed')
        ->and($run2->error['code'])->toBe('REPRODUCTION_FAILED')
        ->and($run2->error['message'])->toBe('Vulnerability not triggered')
        ->and($run2->completed_at)->not->toBeNull();

    // 4. Fail run with Exception
    $run3 = $tracker->start($incident, 'patch', 1);
    $tracker->fail($run3, new RuntimeException('Syntax error in generator'));

    $run3->refresh();
    expect($run3->status)->toBe('failed')
        ->and($run3->error['code'])->toBe('RuntimeException')
        ->and($run3->error['message'])->toBe('Syntax error in generator');
});

test('Incident model has agentRuns relationship', function () {
    $incident = Incident::factory()->create();
    $tracker = app(AgentRunTracker::class);

    $tracker->start($incident, 'triage', 1);
    $tracker->start($incident, 'reproduction', 1);

    expect($incident->agentRuns)->toHaveCount(2)
        ->and($incident->agentRuns->first()->agent_type)->toBe('triage')
        ->and($incident->agentRuns->last()->agent_type)->toBe('reproduction');
});

test('TriageIncidentJob records AgentRun telemetry in database', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_01',
                    'name' => 'record_triage_analysis',
                    'input' => [
                        'severity' => 'high',
                        'priority' => 'high',
                        'production_exposed' => true,
                        'affected_component' => 'core/auth',
                        'reason' => 'Auth bypass in login module',
                    ],
                ],
            ],
        ], 200),
    ]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    $job = new TriageIncidentJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();
    expect($incident->agentRuns)->toHaveCount(1);

    $run = $incident->agentRuns->first();
    expect($run->agent_type)->toBe('triage')
        ->and($run->status)->toBe('completed')
        ->and($run->output['severity'])->toBe('high')
        ->and($run->output['affected_component'])->toBe('core/auth')
        ->and($run->duration)->not->toBeNull();
});

test('ValidatePatchJob records failure AgentRun telemetry when validation tests fail', function () {
    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->twice();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: false,
        exitCode: 1,
        stdout: 'FAILED: 1 test broken',
        stderr: '',
        executionTimeSeconds: 0.3,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();
    app()->instance(SandboxManagerInterface::class, $mockSandbox);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
        'metadata' => [
            'diff' => "--- a/test.php\n+++ b/test.php\n",
        ],
    ]);

    $job = new ValidatePatchJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();
    expect($incident->agentRuns)->toHaveCount(1);

    $run = $incident->agentRuns->first();
    expect($run->agent_type)->toBe('validation')
        ->and($run->status)->toBe('failed')
        ->and($run->error['code'])->toBe('TEST_FAILED')
        ->and($run->error['message'])->toContain('Automated test runner failed');
});
