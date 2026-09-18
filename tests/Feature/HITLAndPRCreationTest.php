<?php

use App\Enums\IncidentStatus;
use App\Exceptions\Approval\InvalidApprovalException;
use App\Jobs\CreateGitHubPullRequestJob;
use App\Models\Approval;
use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Models\PullRequest;
use App\Models\QualityGateCheck;
use App\Models\QualityGateRun;
use App\Services\Approval\ApprovalValidationService;
use App\Services\GitHub\GitHubPRService;
use App\Tools\MCP\Client\GitHubMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('ApprovalValidationService throws InvalidApprovalException if incident is not in AWAITING_APPROVAL state', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::PATCHING]);
    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => '--- a/f\n+++ b/f\n@@ -1 +1 @@\n+c\n',
    ]);

    $validator = app(ApprovalValidationService::class);

    expect(fn () => $validator->assertCanBeApproved($incident, $patch))
        ->toThrow(InvalidApprovalException::class, 'is not awaiting approval');
});

test('ApprovalValidationService throws InvalidApprovalException if patch iterations exceed ceiling', function () {
    $incident = Incident::factory()->create([
        'status' => IncidentStatus::AWAITING_APPROVAL,
        'patch_iterations' => 4,
    ]);
    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => '--- a/f\n+++ b/f\n@@ -1 +1 @@\n+c\n',
    ]);

    $validator = app(ApprovalValidationService::class);

    expect(fn () => $validator->assertCanBeApproved($incident, $patch))
        ->toThrow(InvalidApprovalException::class, 'exceeds allowable patch iteration limits');
});

test('ApprovalValidationService throws InvalidApprovalException if patch does not belong to incident', function () {
    $incidentA = Incident::factory()->create(['status' => IncidentStatus::AWAITING_APPROVAL]);
    $incidentB = Incident::factory()->create(['status' => IncidentStatus::AWAITING_APPROVAL]);

    $patchOfB = PatchArtifact::create([
        'incident_id' => $incidentB->id,
        'diff' => '--- a/f\n+++ b/f\n@@ -1 +1 @@\n+c\n',
    ]);

    $validator = app(ApprovalValidationService::class);

    expect(fn () => $validator->assertCanBeApproved($incidentA, $patchOfB))
        ->toThrow(InvalidApprovalException::class, 'does not match Incident');
});

test('ApprovalValidationService throws InvalidApprovalException if Quality Gate has not passed', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::AWAITING_APPROVAL]);
    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => '--- a/f\n+++ b/f\n@@ -1 +1 @@\n+c\n',
    ]);

    QualityGateRun::create([
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'iteration' => 1,
        'passed' => false,
    ]);

    $validator = app(ApprovalValidationService::class);

    expect(fn () => $validator->assertCanBeApproved($incident, $patch))
        ->toThrow(InvalidApprovalException::class, 'No successful Quality Gate evaluation found');
});

test('ApprovalValidationService returns latest successful QualityGateRun when invariants are satisfied', function () {
    $incident = Incident::factory()->create([
        'status' => IncidentStatus::AWAITING_APPROVAL,
        'patch_iterations' => 1,
    ]);
    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => '--- a/f\n+++ b/f\n@@ -1 +1 @@\n+c\n',
    ]);

    $gateRun = QualityGateRun::create([
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'iteration' => 1,
        'passed' => true,
    ]);

    $validator = app(ApprovalValidationService::class);
    $verifiedRun = $validator->assertCanBeApproved($incident, $patch);

    expect($verifiedRun->id)->toBe($gateRun->id);
});

test('IncidentApprovalController approves patch, marks old approvals as SUPERSEDED, and dispatches PR job', function () {
    Queue::fake([CreateGitHubPullRequestJob::class]);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::AWAITING_APPROVAL,
        'patch_iterations' => 1,
    ]);

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => '--- a/f\n+++ b/f\n@@ -1 +1 @@\n+c\n',
    ]);

    $gateRun = QualityGateRun::create([
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'iteration' => 1,
        'passed' => true,
    ]);

    // Create a prior approval to verify it gets SUPERSEDED
    Approval::create([
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'quality_gate_run_id' => $gateRun->id,
        'approved_by' => 'old_user',
        'status' => 'PENDING',
        'decision' => 'PENDING',
    ]);

    $response = $this->postJson("/api/v1/incidents/{$incident->id}/patches/{$patch->id}/approve", [
        'comment' => 'Approved by Chief Security Officer after empirical verification.',
        'approved_by' => 'secops_lead_42',
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Patch successfully approved. Initiating branch and pull request creation.',
        ]);

    $this->assertDatabaseHas('approvals', [
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'approved_by' => 'secops_lead_42',
        'status' => 'APPROVED',
        'decision' => 'APPROVE',
        'comment' => 'Approved by Chief Security Officer after empirical verification.',
    ]);

    $this->assertDatabaseHas('approvals', [
        'approved_by' => 'old_user',
        'status' => 'SUPERSEDED',
    ]);

    $this->assertDatabaseHas('incidents', [
        'id' => $incident->id,
        'status' => 'approved',
    ]);

    Queue::assertPushed(CreateGitHubPullRequestJob::class, function (CreateGitHubPullRequestJob $job) use ($incident, $patch) {
        return $job->incident->id === $incident->id
            && $job->patch->id === $patch->id
            && $job->approval->status === 'APPROVED';
    });
});

test('IncidentApprovalController rejects candidate patch and halts automation into ESCALATED state', function () {
    Queue::fake([CreateGitHubPullRequestJob::class]);

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::AWAITING_APPROVAL,
    ]);

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => '--- a/f\n+++ b/f\n@@ -1 +1 @@\n+c\n',
    ]);

    $response = $this->postJson("/api/v1/incidents/{$incident->id}/patches/{$patch->id}/reject", [
        'reason' => 'Patch introduces breaking changes to internal authentication signature.',
        'approved_by' => 'security_officer_7',
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'message' => 'Patch candidate rejected. Incident escalated to security engineering.',
        ]);

    $this->assertDatabaseHas('approvals', [
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'approved_by' => 'security_officer_7',
        'status' => 'REJECTED',
        'decision' => 'REJECT',
    ]);

    $this->assertDatabaseHas('incidents', [
        'id' => $incident->id,
        'status' => 'escalated',
    ]);

    $incident->refresh();
    expect($incident->escalation_reason)->toContain('Human operator rejected patch');

    Queue::assertNotPushed(CreateGitHubPullRequestJob::class);
});

test('GitHubPRService executes branch creation, commit, and pull request with audit payload', function () {
    $incident = Incident::factory()->create([
        'repository' => 'acme/auth-service',
        'metadata' => [
            'cve_id' => 'CVE-2026-9999',
            'commit_sha' => 'd3adb33f1234567890abcdef1234567890abcdef',
            'base_branch' => 'main',
        ],
    ]);

    $patch = PatchArtifact::create([
        'incident_id' => $incident->id,
        'diff' => "--- a/src/Token.php\n+++ b/src/Token.php\n@@ -1 +1 @@\n+return true;\n",
        'fix_summary' => 'Fixed algorithm substitution vulnerability in JWT validation.',
    ]);

    $qualityRun = QualityGateRun::create([
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'iteration' => 1,
        'passed' => true,
        'total_checks' => 2,
        'passed_checks' => 2,
    ]);

    QualityGateCheck::create([
        'quality_gate_run_id' => $qualityRun->id,
        'check_type' => 'patch_integrity',
        'status' => 'passed',
        'exit_code' => 0,
    ]);

    QualityGateCheck::create([
        'quality_gate_run_id' => $qualityRun->id,
        'check_type' => 'vulnerability_retest',
        'status' => 'passed',
        'exit_code' => 0,
    ]);

    $approval = Approval::create([
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'quality_gate_run_id' => $qualityRun->id,
        'approved_by' => 'lead_security_architect',
        'status' => 'APPROVED',
        'decision' => 'APPROVE',
        'approved_at' => now(),
    ]);

    $mockMcp = Mockery::mock(GitHubMcpClient::class);
    $mockMcp->shouldReceive('callTool')
        ->with('create_branch', Mockery::any())
        ->once()
        ->andReturn(['success' => true, 'data' => []]);

    $mockMcp->shouldReceive('callTool')
        ->with('create_or_update_file', Mockery::any())
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'commit' => ['sha' => 'b4dc0ffee1234567890abcdef1234567890abcdef'],
            ],
        ]);

    $mockMcp->shouldReceive('callTool')
        ->with('create_pull_request', Mockery::any())
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'number' => 1337,
                'html_url' => 'https://github.com/acme/auth-service/pull/1337',
            ],
        ]);

    app()->instance(GitHubMcpClient::class, $mockMcp);

    $prService = app(GitHubPRService::class);
    $pr = $prService->executeMutation($incident, $patch, $approval);

    expect($pr)->toBeInstanceOf(PullRequest::class)
        ->and($pr->status)->toBe('OPEN')
        ->and($pr->pr_number)->toBe(1337)
        ->and($pr->pr_url)->toBe('https://github.com/acme/auth-service/pull/1337')
        ->and($pr->commit_sha)->toBe('b4dc0ffee1234567890abcdef1234567890abcdef')
        ->and($pr->branch)->toContain("patchops/{$incident->cve_identifier}-");

    $this->assertDatabaseHas('pull_requests', [
        'id' => $pr->id,
        'incident_id' => $incident->id,
        'patch_id' => $patch->id,
        'repository' => 'acme/auth-service',
        'status' => 'OPEN',
        'pr_number' => 1337,
    ]);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::PR_CREATED);
});
