<?php

use App\Models\Incident;
use App\Services\Sandbox\ProcessResult;
use App\Services\Sandbox\SandboxManagerInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Exceptions\InvalidToolArgumentException;
use App\Tools\Exceptions\ToolNotFoundException;
use App\Tools\Exceptions\UnauthorizedToolException;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ToolRegistry discovers and registers all default tool providers', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->has('github.get_repository'))->toBeTrue()
        ->and($registry->has('github.get_file'))->toBeTrue()
        ->and($registry->has('github.create_pull_request'))->toBeTrue()
        ->and($registry->has('vulnerability.get_cve'))->toBeTrue()
        ->and($registry->has('vulnerability.search'))->toBeTrue()
        ->and($registry->has('repository.inspect_structure'))->toBeTrue()
        ->and($registry->has('repository.search_code'))->toBeTrue()
        ->and($registry->has('sandbox.create_environment'))->toBeTrue()
        ->and($registry->has('sandbox.execute'))->toBeTrue()
        ->and($registry->has('sandbox.destroy_environment'))->toBeTrue();

    expect(fn () => $registry->get('non_existent_tool'))
        ->toThrow(ToolNotFoundException::class);
});

test('ToolRegistry dynamically compiles authorized tool schemas per agent role', function () {
    $registry = app(ToolRegistry::class);

    // Triage role: Should only see GitHub Read, Vulnerability Read, Repo Read tools
    $triageTools = $registry->getToolsForRole(AgentRole::TRIAGE);
    $triageNames = array_map(fn ($tool) => $tool->name, $triageTools);

    expect($triageNames)->toContain('github.get_repository')
        ->and($triageNames)->toContain('vulnerability.get_cve')
        ->and($triageNames)->toContain('repository.search_code')
        ->and($triageNames)->not->toContain('sandbox.execute')
        ->and($triageNames)->not->toContain('github.create_pull_request');

    // Schemas format properly for Claude
    $schemas = $registry->getToolSchemasForRole(AgentRole::TRIAGE);
    expect($schemas)->toBeArray()
        ->and($schemas[0])->toHaveKeys(['name', 'description', 'input_schema']);
});

test('ToolRegistry enforces role authorization before execution', function () {
    $registry = app(ToolRegistry::class);
    $incident = Incident::factory()->create();

    // 1. Triage Agent attempting sandbox.execute -> Unauthorized
    expect(fn () => $registry->execute(
        toolName: 'sandbox.execute',
        arguments: ['workspace_id' => 'ws-1', 'command' => 'php -v'],
        role: AgentRole::TRIAGE,
        incident: $incident,
    ))->toThrow(UnauthorizedToolException::class);

    // 2. Reproduction Agent attempting github.create_pull_request -> Unauthorized
    expect(fn () => $registry->execute(
        toolName: 'github.create_pull_request',
        arguments: ['repository' => 'org/repo', 'title' => 'Fix', 'body' => 'Details', 'branch' => 'fix-branch'],
        role: AgentRole::REPRODUCTION,
        incident: $incident,
    ))->toThrow(UnauthorizedToolException::class);
});

test('ToolRegistry validates required tool arguments against parameter schema', function () {
    $registry = app(ToolRegistry::class);
    $incident = Incident::factory()->create();

    // Missing 'repository' parameter for github.get_repository
    expect(fn () => $registry->execute(
        toolName: 'github.get_repository',
        arguments: [],
        role: AgentRole::TRIAGE,
        incident: $incident,
    ))->toThrow(InvalidToolArgumentException::class);
});

test('ToolRegistry executes authorized tools cleanly and returns structured output', function () {
    $mockSandbox = Mockery::mock(SandboxManagerInterface::class);
    $mockSandbox->shouldReceive('runCommand')->once()->with('test-ws', 'npm test', 60)->andReturn(new ProcessResult(
        success: true,
        exitCode: 0,
        stdout: '12 passed',
        stderr: '',
        executionTimeSeconds: 1.2,
    ));
    app()->instance(SandboxManagerInterface::class, $mockSandbox);

    $registry = new ToolRegistry;
    $incident = Incident::factory()->create();

    $result = $registry->execute(
        toolName: 'sandbox.execute',
        arguments: ['workspace_id' => 'test-ws', 'command' => 'npm test', 'timeout' => 60],
        role: AgentRole::REPRODUCTION,
        incident: $incident,
    );

    expect($result)->toBeArray()
        ->and($result['success'])->toBeTrue()
        ->and($result['stdout'])->toBe('12 passed')
        ->and($result['exit_code'])->toBe(0);
});
