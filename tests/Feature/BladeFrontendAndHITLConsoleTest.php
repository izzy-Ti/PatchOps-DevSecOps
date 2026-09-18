<?php

use App\Enums\IncidentStatus;
use App\Jobs\CreateGitHubPullRequestJob;
use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Models\QualityGateCheck;
use App\Models\QualityGateRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('Operations dashboard renders successfully with KPI cards and incidents table', function () {
    $incident = Incident::factory()->create([
        'incident_number' => 'INC-DASH-001',
        'title' => 'Remote Code Execution in Authentication Module',
        'status' => IncidentStatus::AWAITING_APPROVAL,
    ]);

    $response = $this->get('/incidents');

    $response->assertOk()
        ->assertSee('PatchOps')
        ->assertSee('INC-DASH-001')
        ->assertSee('Remote Code Execution in Authentication Module')
        ->assertSee('Awaiting Human Review')
        ->assertSee('Review &amp; Approve', false);
});

test('Incident show view renders all multi-tab operational sections', function () {
    $incident = Incident::factory()->create([
        'incident_number' => 'INC-SHOW-002',
        'root_cause' => 'Flawed sanitization in JWT decoder parameter.',
    ]);

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => "--- a/src/Auth.php\n+++ b/src/Auth.php\n@@ -1 +1 @@\n+ sanitized\n",
        'status' => 'verified',
    ]);

    $response = $this->get("/incidents/{$incident->id}");

    $response->assertOk()
        ->assertSee('INC-SHOW-002')
        ->assertSee('1. Vulnerability Overview')
        ->assertSee('2. Operational Timeline')
        ->assertSee('3. Patch Iterations')
        ->assertSee('4. Post-Deploy &amp; Verification', false)
        ->assertSee('Flawed sanitization in JWT decoder parameter.');
});

test('HITL approval console renders unified diff and quality gate checklist', function () {
    $incident = Incident::factory()->create([
        'incident_number' => 'INC-APPR-003',
        'status' => IncidentStatus::AWAITING_APPROVAL,
        'patch_iterations' => 1,
    ]);

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => "--- a/src/Security.php\n+++ b/src/Security.php\n@@ -5,3 +5,4 @@\n- eval(\$cmd);\n+ safe_exec(\$cmd);\n",
    ]);

    $run = QualityGateRun::create([
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'iteration' => 1,
        'passed' => true,
        'total_checks' => 8,
        'passed_checks' => 8,
        'failed_checks' => 0,
    ]);

    QualityGateCheck::create([
        'quality_gate_run_id' => $run->id,
        'check_type' => 'patch_integrity',
        'status' => 'PASSED',
        'exit_code' => 0,
        'duration_ms' => 35,
        'stdout' => 'Patch applies cleanly without offset.',
    ]);

    $response = $this->get("/incidents/{$incident->id}/approval");

    $response->assertOk()
        ->assertSee('INC-APPR-003')
        ->assertSee('Candidate Patch Unified Diff')
        ->assertSee('safe_exec')
        ->assertSee('Behavior Inversion Verification')
        ->assertSee('Approve Patch & Open PR')
        ->assertSee('Reject Candidate');
});

test('HITL console rejects patch and redirects back with alert', function () {
    $incident = Incident::factory()->create([
        'status' => IncidentStatus::AWAITING_APPROVAL,
    ]);

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => '--- a/f\n+++ b/f\n',
    ]);

    $response = $this->post("/incidents/{$incident->id}/patches/{$patch->id}/reject", [
        'reason' => 'Candidate fails custom enterprise boundary constraints.',
    ]);

    $response->assertRedirect("/incidents/{$incident->id}");
    expect($incident->fresh()->status)->toBe(IncidentStatus::ESCALATED);
});

test('HITL console approves patch and dispatches CreateGitHubPullRequestJob', function () {
    Queue::fake();

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::AWAITING_APPROVAL,
        'patch_iterations' => 1,
    ]);

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => "--- a/App.php\n+++ b/App.php\n@@ -1 +1 @@\n+ fixed\n",
    ]);

    QualityGateRun::create([
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'iteration' => 1,
        'passed' => true,
        'total_checks' => 8,
        'passed_checks' => 8,
        'failed_checks' => 0,
    ]);

    $response = $this->post("/incidents/{$incident->id}/patches/{$patch->id}/approve", [
        'comment' => 'Verified through automated pipeline and approved by SecOps Lead.',
    ]);

    $response->assertRedirect("/incidents/{$incident->id}");
    expect($incident->fresh()->status)->toBe(IncidentStatus::APPROVED);

    Queue::assertPushed(CreateGitHubPullRequestJob::class);
});

test('Dashboard filters correctly filter incidents by status and severity', function () {
    $awaiting = Incident::factory()->create([
        'incident_number' => 'INC-AWAITING-01',
        'status' => IncidentStatus::AWAITING_APPROVAL,
        'severity' => 'critical',
    ]);

    $triaging = Incident::factory()->create([
        'incident_number' => 'INC-TRIAGING-02',
        'status' => IncidentStatus::TRIAGING,
        'severity' => 'low',
    ]);

    // Test filter by status
    $response = $this->get('/incidents?status=awaiting_approval');
    $response->assertOk()
        ->assertSee('INC-AWAITING-01')
        ->assertDontSee('INC-TRIAGING-02');

    // Test filter by severity
    $response = $this->get('/incidents?severity=critical');
    $response->assertOk()
        ->assertSee('INC-AWAITING-01')
        ->assertDontSee('INC-TRIAGING-02');
});
