<?php

use App\Agents\ReproductionAgent;
use App\DTOs\ReproductionResultDTO;
use App\Enums\IncidentStatus;
use App\Jobs\DispatchPatchAgentJob;
use App\Jobs\ExecuteReproductionJob;
use App\Models\Incident;
use App\Services\Orchestration\IncidentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('IncidentOrchestrator handles successful reproduction and dispatches patch agent job', function () {
    Queue::fake();

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::PRIORITIZED,
        'repository' => 'acme/webapp',
    ]);

    $mockAgent = Mockery::mock(ReproductionAgent::class);
    $mockAgent->shouldReceive('execute')->once()->andReturn(new ReproductionResultDTO(
        reproduced: true,
        exitCode: 0,
        command: 'npm run test:security',
        stdout: 'VULNERABILITY CONFIRMED: Privilege escalation verified.',
        stderr: '',
        durationMs: 2500.0,
        observations: ['Exploit succeeded via unsafe deserialization at src/parser.ts:80'],
    ));

    $orchestrator = new IncidentOrchestrator($mockAgent);
    $orchestrator->handlePrioritized($incident);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::REPRODUCED)
        ->and($incident->metadata['reproduction_result']['reproduced'])->toBeTrue();

    Queue::assertPushed(DispatchPatchAgentJob::class, function ($job) use ($incident) {
        return $job->incident->id === $incident->id;
    });
});

test('IncidentOrchestrator marks incident as TRIAGED_NOT_REPRODUCIBLE when exploit fails', function () {
    Queue::fake();

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::PRIORITIZED,
        'repository' => 'acme/webapp',
    ]);

    $mockAgent = Mockery::mock(ReproductionAgent::class);
    $mockAgent->shouldReceive('execute')->once()->andReturn(new ReproductionResultDTO(
        reproduced: false,
        exitCode: 1,
        command: 'npm run test:security',
        stdout: 'Tests passed cleanly; no exploit behavior triggered.',
        stderr: '',
        durationMs: 1200.0,
        observations: ['Vulnerability payload was neutralized by sanitize() filter.'],
    ));

    $orchestrator = new IncidentOrchestrator($mockAgent);
    $orchestrator->handlePrioritized($incident);

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::TRIAGED_NOT_REPRODUCIBLE)
        ->and($incident->metadata['reproduction_metadata']['reproduced'])->toBeFalse();

    Queue::assertNotPushed(DispatchPatchAgentJob::class);
});

test('IncidentOrchestrator retries reproduction on transient failure and escalates after 3 attempts', function () {
    Queue::fake();

    $incident = Incident::factory()->create([
        'status' => IncidentStatus::PRIORITIZED,
        'repository' => 'acme/webapp',
    ]);

    $mockAgent = Mockery::mock(ReproductionAgent::class);
    $mockAgent->shouldReceive('execute')->andThrow(new RuntimeException('Transient Docker daemon network glitch.'));

    $orchestrator = new IncidentOrchestrator($mockAgent);

    // Attempt 1: retry
    $orchestrator->handlePrioritized($incident);
    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::PRIORITIZED)
        ->and($incident->metadata['reproduction_retries'])->toBe(1);
    Queue::assertPushed(ExecuteReproductionJob::class);

    // Attempt 2: retry
    $orchestrator->handlePrioritized($incident);
    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::PRIORITIZED)
        ->and($incident->metadata['reproduction_retries'])->toBe(2);

    // Attempt 3: escalation
    $orchestrator->handlePrioritized($incident);
    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::ESCALATED)
        ->and($incident->metadata['reproduction_retries'])->toBe(3);
});
