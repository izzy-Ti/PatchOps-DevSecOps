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

test('ValidationAgent evaluates Claude record_review_verdict tool response with approval', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->twice();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: true,
        exitCode: 0,
        stdout: 'Security PoC rejected (mitigated), 14 regression tests passed.',
        stderr: '',
        executionTimeSeconds: 0.5,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'tool_use',
                    'name' => 'record_review_verdict',
                    'input' => [
                        'approved' => true,
                        'verdict_summary' => 'Patch successfully mitigates CVE-2026-0001 and passes all regression suites.',
                        'vulnerability_mitigated' => true,
                        'regressions_detected' => false,
                        'build_passed' => true,
                        'checks' => [
                            [
                                'check_name' => 'Vulnerability Reproduction PoC',
                                'command' => 'npm run test:security',
                                'exit_code' => 1,
                                'passed' => true,
                                'details' => 'Payload rejected with 400 Validation Error.',
                            ],
                            [
                                'check_name' => 'Core Regression Suite',
                                'command' => 'npm test',
                                'exit_code' => 0,
                                'passed' => true,
                                'details' => 'All 14 unit tests passed.',
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::VALIDATING]);

    $agent = new ValidationAgent($mockSandbox);
    $result = $agent->validate($incident);

    expect($result->success)->toBeTrue()
        ->and($result->data['approved'])->toBeTrue()
        ->and($result->data['vulnerability_mitigated'])->toBeTrue()
        ->and($result->data['regressions_detected'])->toBeFalse()
        ->and($result->data['checks'])->toHaveCount(2)
        ->and($result->data['verdict_summary'])->toBe('Patch successfully mitigates CVE-2026-0001 and passes all regression suites.');
});

test('ValidationAgent evaluates Claude record_review_verdict tool response with adversarial rejection and structured failure evidence', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->twice();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: false,
        exitCode: 1,
        stdout: 'FAIL src/auth/service.spec.ts',
        stderr: '',
        executionTimeSeconds: 0.6,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                [
                    'type' => 'tool_use',
                    'name' => 'record_review_verdict',
                    'input' => [
                        'approved' => false,
                        'verdict_summary' => 'Patch successfully mitigates CVE-2026-1234, but breaks legacy token generation.',
                        'vulnerability_mitigated' => true,
                        'regressions_detected' => true,
                        'build_passed' => true,
                        'checks' => [
                            [
                                'check_name' => 'Core Regression Suite',
                                'command' => 'npm test',
                                'exit_code' => 1,
                                'passed' => false,
                                'details' => 'AuthServiceTest > testUserTokenGeneration failed with NullPointerException.',
                            ],
                        ],
                        'failure_evidence' => [
                            'stdout' => "FAIL src/auth/service.spec.ts\n  ● AuthServiceTest › testUserTokenGeneration\n    NullPointerException",
                            'stderr' => '',
                            'failed_tests' => ['AuthServiceTest > testUserTokenGeneration'],
                            'synthesizer_guidance' => 'The null-check added in src/auth/index.ts:42 prevents valid legacy tokens from resolving.',
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::VALIDATING]);

    $agent = new ValidationAgent($mockSandbox);
    $result = $agent->validate($incident);

    expect($result->success)->toBeFalse()
        ->and($result->error->message)->toBe('The null-check added in src/auth/index.ts:42 prevents valid legacy tokens from resolving.')
        ->and($result->error->details['approved'])->toBeFalse()
        ->and($result->error->details['regressions_detected'])->toBeTrue()
        ->and($result->error->details['failure_evidence']['failed_tests'])->toEqual(['AuthServiceTest > testUserTokenGeneration'])
        ->and($result->error->details['failure_evidence']['synthesizer_guidance'])->toContain('legacy tokens from resolving');
});

test('ValidationAgent buildContext includes vulnerability advisory, candidate diff, and reproduction PoC traces', function () {
    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
        'metadata' => [
            'fix_summary' => 'Sanitize untrusted user input',
            'diff' => "--- a/src/App.php\n+++ b/src/App.php\n+ echo 'safe';",
            'reproduction_summary' => 'Remote script execution observed in vulnerable endpoint',
            'poc_script' => 'curl -X POST http://localhost/exploit',
            'reproduction_stdout' => 'VULNERABILITY CONFIRMED: shell escaped',
        ],
    ]);

    $agent = new ValidationAgent(Mockery::mock(SandboxManagerInterface::class));
    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('buildContext');
    $method->setAccessible(true);

    $context = $method->invoke($agent, $incident, 'Test suite run: 1 failure', 'Build finished 0 warnings');

    expect($context)->toContain('# Patch Review & Verification Request')
        ->and($context)->toContain('Sanitize untrusted user input')
        ->and($context)->toContain("--- a/src/App.php\n+++ b/src/App.php")
        ->and($context)->toContain('Remote script execution observed in vulnerable endpoint')
        ->and($context)->toContain('curl -X POST http://localhost/exploit')
        ->and($context)->toContain('VULNERABILITY CONFIRMED: shell escaped')
        ->and($context)->toContain('Test suite run: 1 failure')
        ->and($context)->toContain('Build finished 0 warnings');
});
