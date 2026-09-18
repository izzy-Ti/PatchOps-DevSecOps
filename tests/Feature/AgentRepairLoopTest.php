<?php

use App\Agents\PatchAgent;
use App\DTOs\AgentErrorDTO;
use App\DTOs\AgentResultDTO;
use App\Enums\IncidentStatus;
use App\Jobs\GeneratePatchJob;
use App\Models\Incident;
use App\Workflows\IncidentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.anthropic.key', null);
});

test('Incident model tracks and increments patch repair attempts', function () {
    $incident = Incident::factory()->create();

    expect($incident->getPatchAttempts())->toBe(0)
        ->and($incident->getLatestValidationFeedback())->toBeNull();

    $attempt1 = $incident->incrementPatchAttempts();
    expect($attempt1)->toBe(1)
        ->and($incident->getPatchAttempts())->toBe(1);

    $attempt2 = $incident->incrementPatchAttempts();
    expect($attempt2)->toBe(2)
        ->and($incident->fresh()->getPatchAttempts())->toBe(2);
});

test('IncidentOrchestrator handles repair loop, retries on attempt 1 and 2, and escalates on attempt 3', function () {
    Queue::fake();

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
        'metadata' => [
            'diff' => "--- a/file.php\n+++ b/file.php\n",
        ],
    ]);

    $orchestrator = app(IncidentOrchestrator::class);

    // Attempt 1: Validation fails -> transition to PATCHING and dispatch GeneratePatchJob
    $failResult1 = AgentResultDTO::failure(
        code: AgentErrorDTO::TEST_FAILED,
        message: 'Unit test test_auth_token failed with assertion error.',
        details: ['test_output' => 'Failed asserting that false is true.'],
        metadata: ['agent' => 'ValidationAgent'],
    );
    $orchestrator->handleValidationResult($incident, $failResult1);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::PATCHING)
        ->and($incident->getPatchAttempts())->toBe(1)
        ->and($incident->getLatestValidationFeedback())->toBe('Unit test test_auth_token failed with assertion error.')
        ->and($incident->metadata['validation_history'])->toHaveCount(1)
        ->and($incident->metadata['validation_history'][0]['attempt'])->toBe(1)
        ->and($incident->metadata['error_history'])->toHaveCount(1);

    Queue::assertPushed(GeneratePatchJob::class, 1);

    // Transition back to VALIDATING to simulate next test run
    $incident->update(['status' => IncidentStatus::VALIDATING]);

    // Attempt 2: Validation fails -> transition to PATCHING and dispatch GeneratePatchJob
    $failResult2 = AgentResultDTO::failure(
        code: AgentErrorDTO::TEST_FAILED,
        message: 'TypeError: Argument 1 passed must be of type string.',
        details: ['test_output' => 'Fatal error: Uncaught TypeError.'],
        metadata: ['agent' => 'ValidationAgent'],
    );
    $orchestrator->handleValidationResult($incident, $failResult2);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::PATCHING)
        ->and($incident->getPatchAttempts())->toBe(2)
        ->and($incident->metadata['validation_history'])->toHaveCount(2)
        ->and($incident->metadata['validation_history'][1]['attempt'])->toBe(2);

    Queue::assertPushed(GeneratePatchJob::class, 2);

    // Transition back to VALIDATING to simulate 3rd run
    $incident->update(['status' => IncidentStatus::VALIDATING]);

    // Attempt 3: Validation fails -> hits MAX_PATCH_ITERATIONS threshold (3) -> transition to ESCALATED
    $failResult3 = AgentResultDTO::failure(
        code: AgentErrorDTO::BUILD_FAILED,
        message: 'Memory limit exceeded during validation.',
        metadata: ['agent' => 'ValidationAgent'],
    );
    $orchestrator->handleValidationResult($incident, $failResult3);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::ESCALATED)
        ->and($incident->getPatchAttempts())->toBe(3)
        ->and($incident->metadata['validation_history'])->toHaveCount(3);

    // Should NOT push a 4th GeneratePatchJob
    Queue::assertPushed(GeneratePatchJob::class, 2);
});

test('IncidentOrchestrator transitions to AWAITING_APPROVAL when validation passes during retry loop', function () {
    Queue::fake();

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
        'metadata' => [
            'patch_attempts' => 1,
            'last_validation_feedback' => 'Initial failure',
        ],
    ]);

    $orchestrator = app(IncidentOrchestrator::class);

    $passResult = AgentResultDTO::success(
        data: [
            'test_output' => 'All 15 tests passed',
            'build_output' => 'Build success',
            'summary' => 'Patch verified cleanly after 1 retry',
        ],
        metadata: ['agent' => 'ValidationAgent'],
    );

    $orchestrator->handleValidationResult($incident, $passResult);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::AWAITING_APPROVAL)
        ->and($incident->metadata['validation_summary'])->toBe('Patch verified cleanly after 1 retry');

    Queue::assertNotPushed(GeneratePatchJob::class);
});

test('PatchAgent includes previous validation diagnostics in prompt context when retrying', function () {
    $incident = Incident::factory()->create([
        'status' => IncidentStatus::PATCHING,
        'metadata' => [
            'patch_attempts' => 2,
            'last_validation_feedback' => 'Null pointer exception on line 42 of SecurityService.php',
            'validation_test_output' => 'Call to a member function sanitize() on null',
            'validation_build_output' => 'Build succeeded with 1 warning',
            'diff' => "--- a/SecurityService.php\n+++ b/SecurityService.php\n@@ -42 +42 @@\n",
        ],
    ]);

    $agent = new PatchAgent;
    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('buildContext');
    $method->setAccessible(true);

    $context = $method->invoke($agent, $incident);

    expect($context)->toContain('PREVIOUS ATTEMPT FAILED (Attempt 2 of 3)')
        ->and($context)->toContain('Null pointer exception on line 42 of SecurityService.php')
        ->and($context)->toContain('Call to a member function sanitize() on null')
        ->and($context)->toContain('--- a/SecurityService.php');
});

test('IncidentOrchestrator routes Reviewer structured feedback and synthesizer guidance into PatchAgent retry context', function () {
    Queue::fake();

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
        'metadata' => [
            'diff' => "--- a/src/Token.php\n+++ b/src/Token.php\n",
        ],
    ]);

    $orchestrator = app(IncidentOrchestrator::class);

    $adversarialRejection = AgentResultDTO::failure(
        code: AgentErrorDTO::TEST_FAILED,
        message: 'The null-check added in src/Token.php:30 breaks legacy JWT resolution.',
        details: [
            'approved' => false,
            'verdict_summary' => 'Patch mitigates CVE-2026-9999 but causes regression in legacy token parsing.',
            'checks' => [
                [
                    'check_name' => 'Legacy JWT Parser Test',
                    'command' => 'npm test -- -t LegacyTokenTest',
                    'exit_code' => 1,
                    'passed' => false,
                    'details' => 'Expected token object, received null',
                ],
            ],
            'failure_evidence' => [
                'stdout' => 'FAIL tests/LegacyTokenTest.js',
                'stderr' => '',
                'failed_tests' => ['LegacyTokenTest > testParseLegacyBearerToken'],
                'synthesizer_guidance' => 'Ensure null safety while preserving fallback for unpadded base64 legacy tokens.',
            ],
        ],
        metadata: ['agent' => 'ValidationAgent'],
    );

    $orchestrator->handleValidationResult($incident, $adversarialRejection);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::PATCHING)
        ->and($incident->metadata['last_synthesizer_guidance'])->toBe('Ensure null safety while preserving fallback for unpadded base64 legacy tokens.')
        ->and($incident->metadata['last_failed_tests'])->toEqual(['LegacyTokenTest > testParseLegacyBearerToken'])
        ->and($incident->metadata['last_validation_checks'])->toHaveCount(1);

    Queue::assertPushed(GeneratePatchJob::class, 1);

    // Verify PatchAgent generates context with the Reviewer's synthesizer guidance and failed tests
    $agent = new PatchAgent;
    $reflection = new ReflectionClass($agent);
    $method = $reflection->getMethod('buildContext');
    $method->setAccessible(true);

    $promptContext = $method->invoke($agent, $incident);

    expect($promptContext)->toContain('Reviewer Guidance for Synthesizer:')
        ->and($promptContext)->toContain('Ensure null safety while preserving fallback for unpadded base64 legacy tokens.')
        ->and($promptContext)->toContain('Failed Tests Detected: LegacyTokenTest > testParseLegacyBearerToken')
        ->and($promptContext)->toContain('Failed Verification Checks:')
        ->and($promptContext)->toContain('Legacy JWT Parser Test: Expected token object, received null');
});
