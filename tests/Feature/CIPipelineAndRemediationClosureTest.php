<?php

use App\Enums\IncidentStatus;
use App\Enums\RemediationStatus;
use App\Enums\VerificationCheckType;
use App\Jobs\MonitorCIPipelineJob;
use App\Jobs\MonitorDeploymentJob;
use App\Jobs\RunPostDeploymentVerificationJob;
use App\Models\Incident;
use App\Models\RemediationRun;
use App\Models\VerificationCheck;
use App\Services\CICD\CIPipelineService;
use App\Services\Deployment\DeploymentMonitoringService;
use App\Services\Verification\HealthVerificationService;
use App\Services\Verification\SecurityVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('CIPipelineService monitors PR commit SHA and records verification check', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::CI_RUNNING]);
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::CI_RUNNING,
        'environment' => 'staging',
    ]);

    $service = app(CIPipelineService::class);
    $result = $service->monitor($incident, $run, [
        'commit_sha' => 'abc1234',
        'ci_passed' => true,
    ]);

    expect($result->passed)->toBeTrue()
        ->and($result->commitSha)->toBe('abc1234')
        ->and($run->fresh()->status)->toBe(RemediationStatus::CI_PASSED);

    $check = VerificationCheck::where('remediation_run_id', $run->id)
        ->where('check_type', VerificationCheckType::CI_PIPELINE)
        ->first();

    expect($check)->not->toBeNull()
        ->and($check->status)->toBe('PASSED')
        ->and($check->exit_code)->toBe(0);
});

test('MonitorCIPipelineJob advances incident to MERGED and dispatches MonitorDeploymentJob upon CI pass', function () {
    Queue::fake([MonitorDeploymentJob::class]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::PR_CREATED]);
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::PR_CREATED,
        'environment' => 'staging',
    ]);

    $job = new MonitorCIPipelineJob($incident, $run, ['ci_passed' => true]);
    $job->handle(app(CIPipelineService::class));

    expect($incident->fresh()->status)->toBe(IncidentStatus::MERGED);

    Queue::assertPushed(MonitorDeploymentJob::class, function ($job) use ($incident, $run) {
        return $job->incident->id === $incident->id && $job->run->id === $run->id;
    });
});

test('MonitorCIPipelineJob loops back to PATCHING when CI fails and iterations < 3', function () {
    Queue::fake();

    $incident = Incident::factory()->create(['status' => IncidentStatus::CI_RUNNING]);
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::CI_RUNNING,
        'environment' => 'staging',
    ]);

    $job = new MonitorCIPipelineJob($incident, $run, [
        'ci_passed' => false,
        'iteration' => 1,
    ]);
    $job->handle(app(CIPipelineService::class));

    expect($incident->fresh()->status)->toBe(IncidentStatus::PATCHING)
        ->and($run->fresh()->status)->toBe(RemediationStatus::CI_FAILED);
});

test('MonitorCIPipelineJob escalates incident when CI fails and iteration limit reached (iteration >= 3)', function () {
    Queue::fake();

    $incident = Incident::factory()->create(['status' => IncidentStatus::CI_RUNNING]);
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::CI_RUNNING,
        'environment' => 'staging',
    ]);

    $job = new MonitorCIPipelineJob($incident, $run, [
        'ci_passed' => false,
        'iteration' => 3,
    ]);
    $job->handle(app(CIPipelineService::class));

    expect($incident->fresh()->status)->toBe(IncidentStatus::ESCALATED)
        ->and($run->fresh()->status)->toBe(RemediationStatus::ESCALATED);
});

test('DeploymentMonitoringService tracks environment rollout status and records check', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::DEPLOYING]);
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::DEPLOYING,
        'environment' => 'production',
    ]);

    $service = app(DeploymentMonitoringService::class);
    $result = $service->monitor($incident, $run, [
        'deployment_success' => true,
        'environment' => 'production',
    ]);

    expect($result->deployed)->toBeTrue()
        ->and($result->environment)->toBe('production')
        ->and($run->fresh()->status)->toBe(RemediationStatus::DEPLOYED);

    $check = VerificationCheck::where('remediation_run_id', $run->id)
        ->where('check_type', VerificationCheckType::DEPLOYMENT_STATUS)
        ->first();

    expect($check)->not->toBeNull()
        ->and($check->status)->toBe('PASSED');
});

test('HealthVerificationService deterministically requires strict HTTP 200 and DB/cache health', function () {
    $incident = Incident::factory()->create();
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::DEPLOYED,
    ]);

    $service = app(HealthVerificationService::class);

    // Pass scenario
    $healthyResult = $service->verify($incident, $run, [
        'status_code' => 200,
        'db_healthy' => true,
        'cache_healthy' => true,
    ]);

    expect($healthyResult->passed)->toBeTrue()
        ->and($healthyResult->statusCode)->toBe(200)
        ->and($run->fresh()->health_status)->toBe('HEALTHY');

    // Fail scenario (degraded 503)
    $degradedResult = $service->verify($incident, $run, [
        'status_code' => 503,
        'db_healthy' => false,
        'cache_healthy' => true,
    ]);

    expect($degradedResult->passed)->toBeFalse()
        ->and($run->fresh()->health_status)->toBe('UNHEALTHY');
});

test('SecurityVerificationService verifies PoC behavior inversion and exploit blocking', function () {
    $incident = Incident::factory()->create();
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::DEPLOYED,
    ]);

    $service = app(SecurityVerificationService::class);

    // Exploit successfully blocked
    $blockedResult = $service->verify($incident, $run, [
        'exploit_blocked' => true,
        'status_code' => 403,
    ]);

    expect($blockedResult->passed)->toBeTrue()
        ->and($blockedResult->exploitBlocked)->toBeTrue()
        ->and($run->fresh()->security_status)->toBe('SECURE');

    // Exploit regression: payload still active with 200 OK
    $unblockedResult = $service->verify($incident, $run, [
        'exploit_blocked' => false,
        'status_code' => 200,
    ]);

    expect($unblockedResult->passed)->toBeFalse()
        ->and($run->fresh()->security_status)->toBe('VULNERABLE');
});

test('RunPostDeploymentVerificationJob transitions incident and run to REMEDIATED with remediated_at timestamp', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::DEPLOYED]);
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::DEPLOYED,
    ]);

    $job = new RunPostDeploymentVerificationJob($incident, $run, [
        'status_code' => 200,
        'db_healthy' => true,
        'cache_healthy' => true,
        'exploit_blocked' => true,
    ]);

    $job->handle(
        app(HealthVerificationService::class),
        app(SecurityVerificationService::class)
    );

    $refreshedIncident = $incident->fresh();
    $refreshedRun = $run->fresh();

    expect($refreshedIncident->status)->toBe(IncidentStatus::REMEDIATED)
        ->and($refreshedIncident->remediated_at)->not->toBeNull()
        ->and($refreshedRun->status)->toBe(RemediationStatus::REMEDIATED);
});

test('RunPostDeploymentVerificationJob escalates incident for rollback if health verification fails', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::DEPLOYED]);
    $run = RemediationRun::create([
        'incident_id' => $incident->id,
        'status' => RemediationStatus::DEPLOYED,
    ]);

    $job = new RunPostDeploymentVerificationJob($incident, $run, [
        'status_code' => 500, // Health probe fails
        'db_healthy' => false,
        'cache_healthy' => false,
        'exploit_blocked' => true,
    ]);

    $job->handle(
        app(HealthVerificationService::class),
        app(SecurityVerificationService::class)
    );

    expect($incident->fresh()->status)->toBe(IncidentStatus::ESCALATED)
        ->and($run->fresh()->status)->toBe(RemediationStatus::FAILED);
});
