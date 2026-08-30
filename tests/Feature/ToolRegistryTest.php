<?php

use App\Models\Incident;
use App\Services\Sandbox\DockerSandboxManager;
use App\Services\Sandbox\DTOs\SandboxExecutionResultDTO;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Exceptions\InvalidToolArgumentException;
use App\Tools\Exceptions\ToolNotFoundException;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ToolRegistry registers and retrieves tool instances', function () {
    $registry = new ToolRegistry;
    expect($registry->has('github.get_repository'))->toBeTrue()
        ->and($registry->has('vulnerability.get_cve'))->toBeTrue()
        ->and($registry->has('sandbox.execute'))->toBeTrue();

    $tool = $registry->get('github.get_repository');
    expect($tool)->toBeInstanceOf(ToolInterface::class);
});

test('ToolRegistry throws ToolNotFoundException for unregistered tools', function () {
    $registry = new ToolRegistry;
    expect(fn () => $registry->get('unknown.tool'))->toThrow(ToolNotFoundException::class);
});

test('ToolRegistry authorizes tools according to least privilege role matrix', function () {
    $registry = new ToolRegistry;

    // Triage Agent
    expect($registry->authorize('github.get_repository', AgentRole::TRIAGE))->toBeTrue()
        ->and($registry->authorize('vulnerability.get_cve', AgentRole::TRIAGE))->toBeTrue()
        ->and($registry->authorize('sandbox.execute', AgentRole::TRIAGE))->toBeFalse()
        ->and($registry->authorize('github.create_pull_request', AgentRole::TRIAGE))->toBeFalse();

    // Reproduction Agent
    expect($registry->authorize('sandbox.execute', AgentRole::REPRODUCTION))->toBeTrue()
        ->and($registry->authorize('github.create_pull_request', AgentRole::REPRODUCTION))->toBeFalse();

    // Patch Agent
    expect($registry->authorize('github.create_pull_request', AgentRole::PATCH))->toBeTrue();
});

test('ToolRegistry generates valid OpenAI / Anthropic function calling schemas', function () {
    $registry = new ToolRegistry;
    $schemas = $registry->getToolSchemasForRole(AgentRole::TRIAGE);

    expect($schemas)->toBeArray()
        ->and(count($schemas))->toBeGreaterThan(0);

    $first = $schemas[0];
    expect($first)->toHaveKeys(['name', 'description', 'input_schema']);
});

test('ToolRegistry validates input arguments against parameter schema before execution', function () {
    $registry = new ToolRegistry;
    $incident = Incident::factory()->create();

    expect(fn () => $registry->execute(
        toolName: 'github.get_file',
        arguments: [],
        role: AgentRole::TRIAGE,
        incident: $incident,
    ))->toThrow(InvalidToolArgumentException::class);
});

test('ToolRegistry executes authorized tools cleanly and returns structured output', function () {
    $mockSandbox = Mockery::mock(DockerSandboxManager::class);
    $mockSandbox->shouldReceive('execute')->once()->with('test-ws', 'npm test', 60)->andReturn(new SandboxExecutionResultDTO(
        success: true,
        exitCode: 0,
        stdout: '12 passed',
        stderr: '',
        durationSeconds: 1.2,
    ));
    app()->instance(DockerSandboxManager::class, $mockSandbox);

    $registry = new ToolRegistry;
    $incident = Incident::factory()->create();

    $result = $registry->execute(
        toolName: 'sandbox.execute',
        arguments: ['workspace_id' => 'test-ws', 'command' => 'npm test', 'timeout_seconds' => 60],
        role: AgentRole::REPRODUCTION,
        incident: $incident,
    );

    expect($result)->toBeArray()
        ->and($result['success'])->toBeTrue()
        ->and($result['stdout'])->toBe('12 passed')
        ->and($result['exit_code'])->toBe(0);
});
