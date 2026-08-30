<?php

use App\Agents\ReproductionAgent;
use App\DTOs\AgentResultDTO;
use App\Enums\IncidentStatus;
use App\Jobs\GeneratePatchJob;
use App\Jobs\ReproduceIncidentJob;
use App\Models\Incident;
use App\Models\Vulnerability;
use App\Services\Sandbox\DockerSandboxService;
use App\Services\Sandbox\ProcessResult;
use App\Services\Sandbox\SandboxManagerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

test('ReproductionAgent successfully reproduces vulnerability when confirmation indicator is present', function () {
    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once()->andReturn('/tmp/workspace');
    $mockSandbox->shouldReceive('writeFile')->once();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: true,
        exitCode: 0,
        stdout: "[VULNERABILITY_CONFIRMED] - SQL injection successful\n",
        stderr: '',
        executionTimeSeconds: 0.45,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();

    $vuln = Vulnerability::factory()->create([
        'package_name' => 'laravel/framework',
        'cve_id' => 'CVE-2026-1111',
    ]);

    $incident = Incident::factory()->create([
        'vulnerability_id' => $vuln->id,
        'status' => IncidentStatus::PRIORITIZED,
    ]);

    $agent = new ReproductionAgent($mockSandbox);
    $result = $agent->reproduce($incident);

    expect($result)->toBeInstanceOf(AgentResultDTO::class)
        ->and($result->success)->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and($result->data['reproduced'])->toBeTrue()
        ->and($result->data['summary'])->toContain('Vulnerability reproduced successfully');
});

test('ReproductionAgent returns failed DTO when vulnerability indicator is missing', function () {
    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->once();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: true,
        exitCode: 0,
        stdout: "All tests passed cleanly.\n",
        stderr: '',
        executionTimeSeconds: 0.3,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();

    $incident = Incident::factory()->create(['status' => IncidentStatus::PRIORITIZED]);

    $agent = new ReproductionAgent($mockSandbox);
    $result = $agent->reproduce($incident);

    expect($result->success)->toBeFalse()
        ->and($result->error?->code)->toBe('REPRODUCTION_FAILED')
        ->and($result->error?->message)->toContain('did not trigger vulnerability indicator');
});

test('ReproduceIncidentJob executes reproduction and transitions incident to REPRODUCED and dispatches GeneratePatchJob', function () {
    Queue::fake([GeneratePatchJob::class]);

    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->once();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: true,
        exitCode: 0,
        stdout: "[VULNERABILITY_CONFIRMED] - Exploit probe succeeded\n",
        stderr: '',
        executionTimeSeconds: 0.5,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();
    app()->instance(SandboxManagerInterface::class, $mockSandbox);

    $incident = Incident::factory()->create(['status' => IncidentStatus::PRIORITIZED]);

    $job = new ReproduceIncidentJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::REPRODUCED)
        ->and($incident->metadata['reproduction_summary'])->toContain('Vulnerability reproduced successfully')
        ->and($incident->metadata['reproduced_at'])->not->toBeNull();

    Queue::assertPushed(GeneratePatchJob::class, function ($job) use ($incident) {
        return $job->incident->id === $incident->id;
    });
});

test('ReproduceIncidentJob transitions incident to FAILED when reproduction test fails to observe vulnerability', function () {
    Queue::fake([GeneratePatchJob::class]);

    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('createWorkspace')->once();
    $mockSandbox->shouldReceive('writeFile')->once();
    $mockSandbox->shouldReceive('runCommand')->once()->andReturn(new ProcessResult(
        success: true,
        exitCode: 0,
        stdout: "Normal output\n",
        stderr: '',
        executionTimeSeconds: 0.2,
    ));
    $mockSandbox->shouldReceive('cleanup')->once();
    app()->instance(SandboxManagerInterface::class, $mockSandbox);

    $incident = Incident::factory()->create(['status' => IncidentStatus::PRIORITIZED]);

    $job = new ReproduceIncidentJob($incident);
    app()->call([$job, 'handle']);

    $incident->refresh();

    expect($incident->status)->toBe(IncidentStatus::FAILED)
        ->and($incident->metadata['error_history'])->toHaveCount(1);

    Queue::assertNotPushed(GeneratePatchJob::class);
});
