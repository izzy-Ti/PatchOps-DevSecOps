<?php

use App\Agents\TriageAgent;
use App\Enums\IncidentStatus;
use App\Exceptions\TransientAgentInfrastructureException;
use App\Jobs\GeneratePatchJob;
use App\Jobs\ReproduceIncidentJob;
use App\Jobs\TriageIncidentJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('Agent queue jobs define standardized technical retry configuration', function () {
    $incident = Incident::factory()->create();

    $jobs = [
        new TriageIncidentJob($incident),
        new ReproduceIncidentJob($incident),
        new GeneratePatchJob($incident),
        new ValidatePatchJob($incident),
    ];

    foreach ($jobs as $job) {
        expect($job->tries)->toBe(3)
            ->and($job->maxExceptions)->toBe(3)
            ->and($job->backoff())->toBe([5, 20, 60])
            ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
    }
});

test('Job failed hook transitions incident to ESCALATED when technical retries are exhausted', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::TRIAGING]);

    $job = new TriageIncidentJob($incident);
    $job->failed(new RuntimeException('cURL error 7: Failed to connect to host'));

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::ESCALATED)
        ->and($incident->transitions->last()->reason)->toContain('Technical infrastructure failure in TriageIncidentJob: cURL error 7');
});

test('TriageAgent throws TransientAgentInfrastructureException on 429 and 503 HTTP errors to trigger queue retries', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response(['error' => 'Rate limit exceeded'], 429),
    ]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);
    $agent = new TriageAgent;

    expect(fn () => $agent->analyze($incident))
        ->toThrow(TransientAgentInfrastructureException::class);
});
