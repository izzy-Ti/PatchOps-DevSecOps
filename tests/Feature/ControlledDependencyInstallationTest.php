<?php

use App\Models\Incident;
use App\Models\ToolExecution;
use App\Services\MCP\MCPToolGateway;
use App\Services\Sandbox\DTOs\DependencyInstallResultDTO;
use App\Tools\Enums\AgentRole;
use App\Tools\MCP\Sandbox\InstallDependenciesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('DependencyInstallResultDTO constructs and serializes correctly', function () {
    $dto = DependencyInstallResultDTO::fromArray([
        'success' => true,
        'sandbox_id' => 'sb_01KABC1234XYZ',
        'ecosystem' => 'node',
        'manifest_detected' => 'package-lock.json',
        'command_executed' => 'npm ci --ignore-scripts',
        'exit_code' => 0,
        'duration_ms' => 4520.5,
        'stdout' => 'added 150 packages in 4s',
        'stderr' => '',
    ]);

    expect($dto->success)->toBeTrue()
        ->and($dto->sandboxId)->toBe('sb_01KABC1234XYZ')
        ->and($dto->ecosystem)->toBe('node')
        ->and($dto->manifestDetected)->toBe('package-lock.json')
        ->and($dto->commandExecuted)->toBe('npm ci --ignore-scripts')
        ->and($dto->exitCode)->toBe(0)
        ->and($dto->durationMs)->toBe(4520.5);

    $array = $dto->toArray();
    expect($array['command_executed'])->toBe('npm ci --ignore-scripts')
        ->and($array['ecosystem'])->toBe('node');
});

test('InstallDependenciesTool parameters schema only accepts sandbox_id and optional manifest_path', function () {
    $tool = app(InstallDependenciesTool::class);
    $schema = $tool->parametersSchema();

    expect($schema['required'])->toEqual(['sandbox_id'])
        ->and(array_keys($schema['properties']))->toEqual(['sandbox_id', 'manifest_path']);
});

test('MCPToolGateway executes controlled install_dependencies with automated manifest detection', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    $sandboxId = 'sb_01KTESTAUTOINSTALL';

    $result = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.install_dependencies',
        arguments: [
            'sandbox_id' => $sandboxId,
            'manifest_path' => '/app',
        ],
        context: $incident,
    );

    expect($result['success'])->toBeTrue()
        ->and($result['data']['sandbox_id'])->toBe($sandboxId)
        ->and($result['data']['command_executed'])->not->toBeEmpty();

    $execution = ToolExecution::where('incident_id', $incident->id)
        ->where('tool_name', 'sandbox.install_dependencies')
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->status)->toBe('success');
});
