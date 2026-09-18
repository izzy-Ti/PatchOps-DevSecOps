<?php

use App\Enums\IncidentStatus;
use App\Jobs\DispatchPatchAgentJob;
use App\Jobs\RunQualityGateJob;
use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Models\QualityGateCheck;
use App\Services\QualityGate\Checks\VulnerabilityCheck;
use App\Services\QualityGate\QualityGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('QualityGateService executes 9 deterministic checks and passes when all pass', function () {
    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
        'repository' => 'acme/security-lib',
    ]);

    $validDiff = <<<'DIFF'
--- a/src/Auth.php
+++ b/src/Auth.php
@@ -10,6 +10,7 @@
+    public function secureVerify() { return true; }
--- a/tests/AuthTest.php
+++ b/tests/AuthTest.php
@@ -1,5 +1,6 @@
+    public function testSecureVerify() { $this->assertTrue(true); }
DIFF;

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'iteration' => 1,
        'diff' => $validDiff,
        'fix_summary' => 'Fix authorization bypass in secureVerify()',
        'tests_added' => ['tests/AuthTest.php'],
        'status' => 'candidate',
    ]);

    $service = app(QualityGateService::class);
    $result = $service->evaluate($incident, $patch, [
        'vulnerability_retest_exit_code' => 1, // Non-zero indicates exploit is blocked (mitigation verified)
    ]);

    expect($result->passed)->toBeTrue()
        ->and($result->totalChecks())->toBe(9)
        ->and($result->passedChecksCount())->toBe(9)
        ->and($result->failedChecksCount())->toBe(0);

    $this->assertDatabaseHas('quality_gate_runs', [
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'passed' => true,
        'total_checks' => 9,
        'passed_checks' => 9,
        'failed_checks' => 0,
    ]);

    expect(QualityGateCheck::count())->toBe(9);
});

test('QualityGateService triggers fast-fail on critical check failure', function () {
    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
    ]);

    // Invalid diff with no headers
    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'iteration' => 1,
        'diff' => 'corrupted plain text diff without patch headers',
        'fix_summary' => 'Invalid diff',
        'status' => 'candidate',
    ]);

    $service = app(QualityGateService::class);
    $result = $service->evaluate($incident, $patch);

    expect($result->passed)->toBeFalse()
        ->and($result->failedCheck)->not->toBeNull()
        ->and($result->failedCheck->name)->toBe('patch_integrity')
        // Fast-fail halts execution immediately after the first critical check failure
        ->and($result->totalChecks())->toBe(1);

    $this->assertDatabaseHas('quality_gate_runs', [
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'passed' => false,
        'failed_checks' => 1,
    ]);
});

test('VulnerabilityCheck enforces exploit PoC behavior inversion: Exit 0 = unmitigated failure, Exit Non-Zero = blocked pass', function () {
    $incident = Incident::factory()->create();
    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'iteration' => 1,
        'diff' => "--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@\n+code\n",
    ]);

    $check = new VulnerabilityCheck;

    // Before patch / unmitigated: PoC exits with 0 -> Exploit succeeded -> Check FAILS
    $failResult = $check->execute($incident, $patch, [
        'vulnerability_retest_exit_code' => 0,
    ]);
    expect($failResult->passed())->toBeFalse()
        ->and($failResult->name)->toBe('vulnerability_retest')
        ->and($failResult->exitCode)->toBe(1);

    // After patch / mitigated: PoC exits with 1 (or any non-zero) -> Exploit blocked -> Check PASSES
    $passResult = $check->execute($incident, $patch, [
        'vulnerability_retest_exit_code' => 1,
    ]);
    expect($passResult->passed())->toBeTrue()
        ->and($passResult->exitCode)->toBe(0)
        ->and($passResult->stdout)->toContain('Exploit behavior inversion confirmed');
});

test('RunQualityGateJob advances incident to AWAITING_APPROVAL when all gates pass', function () {
    Queue::fake();

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::PATCHING,
        'patch_iterations' => 1,
    ]);

    $validDiff = <<<'DIFF'
--- a/app/Model.php
+++ b/app/Model.php
@@ -1 +1 @@
+secure
--- a/tests/ModelTest.php
+++ b/tests/ModelTest.php
@@ -1 +1 @@
+test
DIFF;

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'iteration' => 1,
        'diff' => $validDiff,
        'status' => 'candidate',
    ]);

    $job = new RunQualityGateJob($incident, $patch, [
        'vulnerability_retest_exit_code' => 1,
    ]);
    app()->call([$job, 'handle']);

    $incident->refresh();
    $patch->refresh();

    expect($incident->status)->toBe(IncidentStatus::AWAITING_APPROVAL)
        ->and($patch->status)->toBe('verified');
});

test('RunQualityGateJob loops back to PATCHING and dispatches DispatchPatchAgentJob when iteration < 3', function () {
    Queue::fake([DispatchPatchAgentJob::class]);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
        'patch_iterations' => 1,
    ]);

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'iteration' => 1,
        'diff' => 'corrupted diff',
        'status' => 'candidate',
    ]);

    $job = new RunQualityGateJob($incident, $patch);
    app()->call([$job, 'handle']);

    $incident->refresh();
    $patch->refresh();

    expect($incident->status)->toBe(IncidentStatus::PATCHING)
        ->and($incident->patch_iterations)->toBe(2)
        ->and($patch->status)->toBe('rejected');

    Queue::assertPushed(DispatchPatchAgentJob::class, function (DispatchPatchAgentJob $job) use ($incident) {
        return $job->incident->id === $incident->id
            && ! empty($job->failureEvidence)
            && $job->failureEvidence['failed_check'] === 'patch_integrity';
    });
});

test('RunQualityGateJob escalates incident when maximum 3 patch iterations are reached', function () {
    Queue::fake([DispatchPatchAgentJob::class]);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::VALIDATING,
        'patch_iterations' => 3,
    ]);

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'iteration' => 3,
        'diff' => 'corrupted diff',
        'status' => 'candidate',
    ]);

    $job = new RunQualityGateJob($incident, $patch);
    app()->call([$job, 'handle']);

    $incident->refresh();
    $patch->refresh();

    expect($incident->status)->toBe(IncidentStatus::ESCALATED)
        ->and($incident->escalation_reason)->toContain('Iteration ceiling reached')
        ->and($patch->status)->toBe('rejected');

    Queue::assertNotPushed(DispatchPatchAgentJob::class);
});
