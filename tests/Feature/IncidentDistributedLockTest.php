<?php

use App\DTOs\AgentResultDTO;
use App\Enums\IncidentStatus;
use App\Exceptions\IncidentConcurrentModificationException;
use App\Jobs\GeneratePatchJob;
use App\Jobs\Middleware\LockIncidentExecution;
use App\Jobs\ReproduceIncidentJob;
use App\Jobs\TriageIncidentJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;
use App\Workflows\IncidentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Cache::flush();
});

test('Agent queue jobs register LockIncidentExecution middleware', function () {
    $incident = Incident::factory()->create();

    $triage = new TriageIncidentJob($incident);
    $reproduce = new ReproduceIncidentJob($incident);
    $patch = new GeneratePatchJob($incident);
    $validate = new ValidatePatchJob($incident);

    expect($triage->middleware())->toHaveCount(1)
        ->and($triage->middleware()[0])->toBeInstanceOf(LockIncidentExecution::class)
        ->and($reproduce->middleware()[0])->toBeInstanceOf(LockIncidentExecution::class)
        ->and($patch->middleware()[0])->toBeInstanceOf(LockIncidentExecution::class)
        ->and($validate->middleware()[0])->toBeInstanceOf(LockIncidentExecution::class);
});

test('LockIncidentExecution acquires atomic lock and executes next handler', function () {
    $incident = Incident::factory()->create();
    $job = new TriageIncidentJob($incident);

    $middleware = new LockIncidentExecution;
    $executed = false;

    $middleware->handle($job, function ($passedJob) use (&$executed, $job) {
        $executed = true;
        expect($passedJob)->toBe($job);
    });

    expect($executed)->toBeTrue();
});

test('LockIncidentExecution releases job back to queue when lock is already held', function () {
    $incident = Incident::factory()->create();
    $job = Mockery::mock(TriageIncidentJob::class);
    $job->incident = $incident;
    $job->shouldReceive('release')->once()->with(10);

    // Acquire lock beforehand to simulate concurrent execution by another worker
    config()->set('patchops.locks.incident_wait_seconds', 0);
    $externalLock = Cache::lock("incident:lock:{$incident->id}", 60);
    $externalLock->get();

    $middleware = new LockIncidentExecution;
    $executed = false;

    $middleware->handle($job, function () use (&$executed) {
        $executed = true;
    });

    expect($executed)->toBeFalse();

    $externalLock->release();
});

test('IncidentOrchestrator throws IncidentConcurrentModificationException when lock cannot be acquired', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    // Acquire orchestrator lock beforehand
    $externalLock = Cache::lock("incident:orchestrator:{$incident->id}", 30);
    $externalLock->get();

    $orchestrator = app(IncidentOrchestrator::class);

    expect(fn () => $orchestrator->handle($incident))
        ->toThrow(IncidentConcurrentModificationException::class);

    $externalLock->release();
});

test('IncidentOrchestrator successfully executes transition inside atomic lock', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::TRIAGING]);
    $orchestrator = app(IncidentOrchestrator::class);

    $result = AgentResultDTO::success([
        'severity' => 'critical',
        'priority' => 'critical',
        'production_exposed' => true,
        'affected_component' => 'auth-module',
        'reason' => 'Critical auth vulnerability verified',
    ]);

    $orchestrator->handleTriageResult($incident, $result);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::PRIORITIZED);
    Queue::assertPushed(ReproduceIncidentJob::class);
});
