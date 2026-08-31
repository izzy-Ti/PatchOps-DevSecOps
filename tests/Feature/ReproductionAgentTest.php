<?php

use App\Agents\ReproductionAgent;
use App\DTOs\AgentResultDTO;
use App\Enums\IncidentStatus;
use App\Jobs\GeneratePatchJob;
use App\Jobs\ReproduceIncidentJob;
use App\Models\Incident;
use App\Models\Vulnerability;
use App\Services\Sandbox\DockerSandboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.anthropic.key', null);
});

test('DockerSandboxService creates workspace, writes files, runs commands, and cleans up', function () {
    $sandbox = new DockerSandboxService(storage_path('framework/testing/sandboxes'));
    $workspaceId = 'test-sandbox-'.uniqid();

    $workspacePath = $sandbox->createWorkspace($workspaceId);
    expect(File::exists($workspacePath))->toBeTrue();

    $sandbox->writeFile($workspaceId, 'test.txt', 'Hello Sandbox');
    expect(File::get($workspacePath.DIRECTORY_SEPARATOR.'test.txt'))->toBe('Hello Sandbox');

    $result = $sandbox->runCommand($workspaceId, 'php -r "echo \'PHP_OK\';"');
    expect($result->success)->toBeTrue()
        ->and($result->stdout)->toBe('PHP_OK');

    $sandbox->cleanup($workspaceId);
    expect(File::exists($workspacePath))->toBeFalse();
});

test('ReproductionAgent successfully reproduces vulnerability via deterministic fallback', function () {
    $vuln = Vulnerability::factory()->create([
        'package_name' => 'laravel/framework',
        'cve_id' => 'CVE-2026-1111',
    ]);

    $incident = Incident::factory()->create([
        'vulnerability_id' => $vuln->id,
        'status' => IncidentStatus::REPRODUCING,
    ]);

    $agent = app(ReproductionAgent::class);
    $result = $agent->reproduce($incident);

    expect($result)->toBeInstanceOf(AgentResultDTO::class)
        ->and($result->success)->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and($result->data['reproduced'])->toBeTrue();
});

test('ReproductionAgent reproduces vulnerability via Claude ReAct loop when API is active', function () {
    config()->set('services.anthropic.key', 'sk-ant-test-key');

    $vuln = Vulnerability::factory()->create([
        'package_name' => 'laravel/framework',
        'cve_id' => 'CVE-2026-1111',
    ]);

    $incident = Incident::factory()->create([
        'vulnerability_id' => $vuln->id,
        'status' => IncidentStatus::REPRODUCING,
    ]);

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_repro_123',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_repro_01',
                    'name' => 'record_reproduction_result',
                    'input' => [
                        'reproduced' => true,
                        'command' => 'php artisan test --filter=SecurityVulnTest',
                        'exit_code' => 0,
                        'stdout' => 'Vulnerability confirmed via unit test assertion',
                        'observations' => ['SQL injection payload bypassed filter at QueryBuilder.php:45'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $agent = app(ReproductionAgent::class);
    $result = $agent->reproduce($incident);

    expect($result)->toBeInstanceOf(AgentResultDTO::class)
        ->and($result->success)->toBeTrue()
        ->and($result->data['reproduced'])->toBeTrue()
        ->and($result->data['command'])->toBe('php artisan test --filter=SecurityVulnTest');
});

test('ReproduceIncidentJob executes reproduction and transitions incident to REPRODUCED and dispatches GeneratePatchJob', function () {
    Queue::fake([GeneratePatchJob::class]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::PRIORITIZED]);

    $job = new ReproduceIncidentJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::REPRODUCED)
        ->and($incident->metadata['reproduction_result']['reproduced'])->toBeTrue()
        ->and($incident->metadata['reproduced_at'])->not->toBeNull();

    Queue::assertPushed(GeneratePatchJob::class, function ($job) use ($incident) {
        return $job->incident->id === $incident->id;
    });
});
