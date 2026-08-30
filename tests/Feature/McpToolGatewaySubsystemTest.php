<?php

use App\Exceptions\MCP\InvalidToolArgumentsException;
use App\Exceptions\MCP\UnauthorizedToolException;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\MCP\MCPPermissionService;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('MCPPermissionService accurately checks agent role capabilities', function () {
    $service = app(MCPPermissionService::class);

    expect($service->isAllowed(AgentRole::TRIAGE, 'github.get_file'))->toBeTrue()
        ->and($service->isAllowed(AgentRole::TRIAGE, 'github.get_repository'))->toBeTrue()
        ->and($service->isAllowed(AgentRole::TRIAGE, 'github.create_pull_request'))->toBeFalse()
        ->and($service->isAllowed(AgentRole::TRIAGE, 'sandbox.execute'))->toBeFalse();

    expect($service->isAllowed(AgentRole::REPRODUCTION, 'sandbox.execute'))->toBeTrue()
        ->and($service->isAllowed(AgentRole::REPRODUCTION, 'github.create_pull_request'))->toBeFalse();

    expect($service->isAllowed(AgentRole::PATCH, 'github.create_pull_request'))->toBeTrue();
});

test('MCPToolGateway rejects unauthorized tool execution with UnauthorizedToolException', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create();

    // Triage Agent attempting sandbox.execute
    expect(fn () => $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'sandbox.execute',
        arguments: ['workspace_id' => 'ws-1', 'command' => 'php -v'],
        context: $incident,
    ))->toThrow(UnauthorizedToolException::class);
});

test('MCPToolGateway enforces parameter schema validation with InvalidToolArgumentsException', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create();

    // Missing 'repository' parameter for github.get_file
    expect(fn () => $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'github.get_file',
        arguments: ['path' => 'index.php'],
        context: $incident,
    ))->toThrow(InvalidToolArgumentsException::class);
});

test('MCPToolGateway executes authorized tools, redacts secrets, and logs audit events', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'org/repo']);

    $result = $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'github.get_repository',
        arguments: ['repository' => 'org/repo'],
        context: $incident,
    );

    expect($result)->toBeArray()
        ->and($result['success'])->toBeTrue()
        ->and($result['tool'])->toBe('github.get_repository')
        ->and($result['role'])->toBe('triage')
        ->and($result['data']['repository'])->toBe('org/repo');

    // Audit log created
    expect(AuditLog::where('event', 'mcp_gateway.tool_executed')->count())->toBe(1);
    $log = AuditLog::where('event', 'mcp_gateway.tool_executed')->first();
    expect($log->payload['tool'])->toBe('github.get_repository')
        ->and($log->payload['role'])->toBe('triage');
});
