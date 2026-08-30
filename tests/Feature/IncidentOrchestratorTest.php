<?php

use App\Enums\IncidentStatus;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\GeneratePatchJob;
use App\Jobs\HandleIncidentFailureJob;
use App\Jobs\ReproduceIncidentJob;
use App\Jobs\TriageIncidentJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;
use App\Vulnerability\VulnerabilityIngestionService;
use App\Workflows\IncidentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('IncidentOrchestrator routes incident statuses to correct queue jobs', function () {
    Queue::fake();

    $orchestrator = new IncidentOrchestrator;

    // RECEIVED -> TriageIncidentJob
    $received = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);
    $orchestrator->handle($received);
    Queue::assertPushed(TriageIncidentJob::class, fn ($job) => $job->incident->id === $received->id);

    // PRIORITIZED -> ReproduceVulnerabilityJob
    $prioritized = Incident::factory()->create(['status' => IncidentStatus::PRIORITIZED]);
    $orchestrator->handle($prioritized);
    Queue::assertPushed(ReproduceIncidentJob::class, fn ($job) => $job->incident->id === $prioritized->id);

    // REPRODUCED -> GeneratePatchJob
    $reproduced = Incident::factory()->create(['status' => IncidentStatus::REPRODUCED]);
    $orchestrator->handle($reproduced);
    Queue::assertPushed(GeneratePatchJob::class, fn ($job) => $job->incident->id === $reproduced->id);

    // PATCHING -> ValidatePatchJob
    $patching = Incident::factory()->create(['status' => IncidentStatus::PATCHING]);
    $orchestrator->handle($patching);
    Queue::assertPushed(ValidatePatchJob::class, fn ($job) => $job->incident->id === $patching->id);

    // VALIDATING -> ValidatePatchJob
    $validating = Incident::factory()->create(['status' => IncidentStatus::VALIDATING]);
    $orchestrator->handle($validating);
    Queue::assertPushed(ValidatePatchJob::class, fn ($job) => $job->incident->id === $validating->id);

    // VERIFIED -> CreatePullRequestJob
    $verified = Incident::factory()->create(['status' => IncidentStatus::VERIFIED]);
    $orchestrator->handle($verified);
    Queue::assertPushed(CreatePullRequestJob::class, fn ($job) => $job->incident->id === $verified->id);

    // FAILED -> HandleIncidentFailureJob
    $failed = Incident::factory()->create(['status' => IncidentStatus::FAILED]);
    $orchestrator->handle($failed);
    Queue::assertPushed(HandleIncidentFailureJob::class, fn ($job) => $job->incident->id === $failed->id);

    // ESCALATED -> HandleIncidentFailureJob
    $escalated = Incident::factory()->create(['status' => IncidentStatus::ESCALATED]);
    $orchestrator->handle($escalated);
    Queue::assertPushed(HandleIncidentFailureJob::class, fn ($job) => $job->incident->id === $escalated->id);
});

test('IncidentOrchestrator holds on awaiting approval and terminal states', function () {
    Queue::fake();

    $orchestrator = new IncidentOrchestrator;

    $awaiting = Incident::factory()->create(['status' => IncidentStatus::AWAITING_APPROVAL]);
    $orchestrator->handle($awaiting);

    $resolved = Incident::factory()->create(['status' => IncidentStatus::RESOLVED]);
    $orchestrator->handle($resolved);

    $closed = Incident::factory()->create(['status' => IncidentStatus::CLOSED]);
    $orchestrator->handle($closed);

    Queue::assertNothingPushed();
});

test('state transition automatically triggers orchestrator listener and dispatches downstream job', function () {
    Queue::fake();

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    // Transition to TRIAGING (no automated job expected)
    $incident->transitionTo(IncidentStatus::TRIAGING);
    Queue::assertNothingPushed();

    // Transition to PRIORITIZED (ReproduceVulnerabilityJob expected)
    $incident->transitionTo(IncidentStatus::PRIORITIZED);
    Queue::assertPushed(ReproduceIncidentJob::class, fn ($job) => $job->incident->id === $incident->id);
});

test('newly ingested vulnerability immediately triggers triage workflow job', function () {
    Queue::fake();

    $payload = [
        'alert' => [
            'number' => 101,
            'security_advisory' => [
                'ghsa_id' => 'GHSA-ORCH-0001',
                'summary' => 'Remote Code Execution in YAML Parser',
                'severity' => 'critical',
            ],
            'dependency' => [
                'package' => [
                    'name' => 'symfony/yaml',
                ],
            ],
        ],
        'repository' => [
            'full_name' => 'izzy-Ti/orchestrator-test',
        ],
    ];

    $service = app(VulnerabilityIngestionService::class);
    $incident = $service->ingest($payload, 'github');

    expect($incident->status)->toBe(IncidentStatus::RECEIVED);
    Queue::assertPushed(TriageIncidentJob::class, fn ($job) => $job->incident->id === $incident->id);
});
