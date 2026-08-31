<?php

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Models\Incident;
use App\Services\MCP\MCPToolGateway;
use App\Services\Sandbox\Guards\SandboxSecurityAuditGuard;
use App\Tools\Enums\AgentRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SandboxSecurityAuditGuard blocks Docker socket mounting attempts', function () {
    $guard = app(SandboxSecurityAuditGuard::class);
    $incident = Incident::factory()->create();

    // 1. Direct /var/run/docker.sock bind -> Blocked
    expect(fn () => $guard->validate($incident, [
        'binds' => ['/var/run/docker.sock:/var/run/docker.sock'],
    ], 'sandbox.create_sandbox'))->toThrow(ForbiddenHostCapabilityException::class);

    // 2. Relative or containerd socket bind -> Blocked
    expect(fn () => $guard->validate($incident, [
        'volumes' => ['/run/containerd/containerd.sock:/containerd.sock'],
    ], 'sandbox.create_sandbox'))->toThrow(ForbiddenHostCapabilityException::class);

    // 3. Root directory bind -> Blocked
    expect(fn () => $guard->validate($incident, [
        'binds' => ['/:/workspace'],
    ], 'sandbox.create_sandbox'))->toThrow(ForbiddenHostCapabilityException::class);
});

test('SandboxSecurityAuditGuard blocks host network mode and privileged container escalation', function () {
    $guard = app(SandboxSecurityAuditGuard::class);
    $incident = Incident::factory()->create();

    // 1. Host network attachment -> Blocked
    expect(fn () => $guard->validate($incident, [
        'network_mode' => 'host',
    ], 'sandbox.create_sandbox'))->toThrow(ForbiddenHostCapabilityException::class);

    // 2. Privileged flag -> Blocked
    expect(fn () => $guard->validate($incident, [
        'privileged' => true,
    ], 'sandbox.create_sandbox'))->toThrow(ForbiddenHostCapabilityException::class);
});

test('MCPToolGateway intercepts socket mount and host network breaches in invoke pipeline', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    // Attempting tool execution with forbidden docker socket mount
    $result = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.create_environment',
        arguments: [
            'incident_id' => $incident->incident_number,
            'binds' => ['/var/run/docker.sock:/var/run/docker.sock'],
        ],
        context: $incident,
    );

    expect($result['success'])->toBeFalse()
        ->and($result['error']['code'])->toBe('FORBIDDEN_HOST_CAPABILITY');
});
