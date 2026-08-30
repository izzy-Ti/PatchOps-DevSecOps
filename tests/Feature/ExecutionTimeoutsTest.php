<?php

use App\Enums\IncidentStatus;
use App\Exceptions\SandboxTimeoutException;
use App\Jobs\GeneratePatchJob;
use App\Jobs\ReproduceIncidentJob;
use App\Jobs\TriageIncidentJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;
use App\Services\Sandbox\DockerSandboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('config defines centralized timeout ceilings for jobs and sandbox', function () {
    expect(config('patchops.timeouts.triage_job'))->toBe(120)
        ->and(config('patchops.timeouts.reproduce_job'))->toBe(600)
        ->and(config('patchops.timeouts.patch_job'))->toBe(300)
        ->and(config('patchops.timeouts.validate_job'))->toBe(900)
        ->and(config('patchops.timeouts.sandbox_command'))->toBe(180)
        ->and(config('patchops.timeouts.sandbox_idle'))->toBe(60);
});

test('Agent queue jobs initialize with configured timeout limits', function () {
    $incident = Incident::factory()->create();

    $triage = new TriageIncidentJob($incident);
    $reproduce = new ReproduceIncidentJob($incident);
    $patch = new GeneratePatchJob($incident);
    $validate = new ValidatePatchJob($incident);

    expect($triage->timeout)->toBe(120)
        ->and($reproduce->timeout)->toBe(600)
        ->and($patch->timeout)->toBe(300)
        ->and($validate->timeout)->toBe(900);
});

test('DockerSandboxService throws SandboxTimeoutException when command exceeds timeout limit', function () {
    $sandbox = new DockerSandboxService(storage_path('framework/testing/sandboxes'));
    $workspaceId = 'timeout-test-'.uniqid();

    $sandbox->createWorkspace($workspaceId);

    try {
        // Run PHP sleep command with 1 second timeout limit
        $sandbox->runCommand($workspaceId, 'php -r "sleep(5);"', timeout: 1);
        $this->fail('Expected SandboxTimeoutException was not thrown.');
    } catch (SandboxTimeoutException $e) {
        expect($e->getMessage())->toContain('exceeded timeout limit of 1s');
    } finally {
        $sandbox->cleanup($workspaceId);
    }
});

test('Job failed hook transitions incident to ESCALATED with timeout-handler reason on timeout exception', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::TRIAGING]);

    $job = new TriageIncidentJob($incident);
    $job->failed(new SandboxTimeoutException('Sandbox process exceeded timeout limit of 120s'));

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::ESCALATED)
        ->and($incident->transitions->last()->reason)->toBe('Execution timed out in TriageIncidentJob after 120s limit.')
        ->and($incident->transitions->last()->actor_id)->toBe('timeout-handler');
});
