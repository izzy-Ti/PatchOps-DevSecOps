<?php

use App\Agents\ValidationAgent;
use App\DTOs\AgentResultDTO;
use App\Enums\IncidentStatus;
use App\Jobs\GeneratePatchJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;
use App\Services\Sandbox\ProcessResult;
use App\Services\Sandbox\SandboxManagerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.anthropic.key', null);
});

test('ValidationAgent runs sandbox validation and returns success when tests pass', function () {
    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->twice();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: true,
        exitCode: 0,
        stdout: 'Running regression tests... PASSED (12 tests, 34 assertions)',
        stderr: '',
        executionTimeSeconds: 0.8,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
        'metadata' => [
            'diff' => "--- a/src/File.php\n+++ b/src/File.php\n",
        ],
    ]);

    $agent = new ValidationAgent($mockSandbox);
    $result = $agent->validate($incident);

    expect($result)->toBeInstanceOf(AgentResultDTO::class)
        ->and($result->success)->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and($result->data['summary'])->toContain('passed');
});

test('ValidationAgent evaluates Claude validation tool response when configured', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->twice();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: true,
        exitCode: 0,
        stdout: 'Tests passed',
        stderr: '',
        executionTimeSeconds: 0.5,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'tool_use',
                    'name' => 'record_validation_verdict',
                    'input' => [
                        'passed' => true,
                        'tests_passed' => true,
                        'build_passed' => true,
                        'security_scan_passed' => true,
                        'summary' => 'Comprehensive regression validation verified.',
                    ],
                ],
            ],
        ], 200),
    ]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::VALIDATING]);

    $agent = new ValidationAgent($mockSandbox);
    $result = $agent->validate($incident);

    expect($result->success)->toBeTrue()
        ->and($result->data['summary'])->toBe('Comprehensive regression validation verified.');
});

test('ValidatePatchJob transitions to AWAITING_APPROVAL when validation passes', function () {
    Queue::fake();

    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->twice();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: true,
        exitCode: 0,
        stdout: 'PASSED',
        stderr: '',
        executionTimeSeconds: 0.4,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();
    app()->instance(SandboxManagerInterface::class, $mockSandbox);

    $incident = Incident::factory()->create(['status' => IncidentStatus::VALIDATING]);

    $job = new ValidatePatchJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::AWAITING_APPROVAL)
        ->and($incident->metadata['validation_summary'])->not->toBeNull()
        ->and($incident->metadata['validated_at'])->not->toBeNull();
});

test('ValidatePatchJob loops back to PATCHING and dispatches GeneratePatchJob when tests fail', function () {
    Queue::fake();

    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->twice();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: false,
        exitCode: 1,
        stdout: 'FAILURES! Tests: 10, Assertions: 20, Failures: 1.',
        stderr: '',
        executionTimeSeconds: 0.6,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();
    app()->instance(SandboxManagerInterface::class, $mockSandbox);

    $incident = Incident::factory()->create(['status' => IncidentStatus::VALIDATING]);

    $job = new ValidatePatchJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::PATCHING)
        ->and($incident->metadata['last_validation_feedback'])->toContain('Automated test runner failed');

    Queue::assertPushed(GeneratePatchJob::class, function ($job) use ($incident) {
        return $job->incident->id === $incident->id;
    });
});
