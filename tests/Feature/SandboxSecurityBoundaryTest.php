<?php

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\MCP\Guards\SandboxExecutionGuard;
use App\Services\MCP\MCPToolGateway;
use App\Services\Sandbox\DockerSandboxManager;
use App\Tools\Enums\AgentRole;
use App\Tools\Permissions\SandboxCapabilityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SandboxCapabilityPolicy blocks host escape capabilities and sensitive paths', function () {
    $policy = app(SandboxCapabilityPolicy::class);
    $incident = Incident::factory()->create();

    // 1. Forbidden capabilities
    expect($policy->isForbiddenCapability('host.execute'))->toBeTrue()
        ->and($policy->isForbiddenCapability('docker.socket'))->toBeTrue()
        ->and($policy->isForbiddenCapability('production.database'))->toBeTrue()
        ->and($policy->isForbiddenCapability('system.exec'))->toBeTrue()
        ->and($policy->isForbiddenCapability('sandbox.execute'))->toBeFalse();

    expect(fn () => $policy->assertAllowedCapability('host.execute', $incident))
        ->toThrow(ForbiddenHostCapabilityException::class);

    // 2. Forbidden host paths in arguments
    expect(fn () => $policy->assertSafePaths(['volume' => '/var/run/docker.sock:/docker.sock'], $incident))
        ->toThrow(ForbiddenHostCapabilityException::class);
});

test('SandboxExecutionGuard blocks container breakout and privileged command attempts', function () {
    $guard = app(SandboxExecutionGuard::class);
    $incident = Incident::factory()->create();

    // 1. Sudo escalation attempt -> Blocked
    expect(fn () => $guard->validate(
        incident: $incident,
        arguments: ['workspace_id' => 'sbx-1', 'command' => 'sudo whoami'],
        toolName: 'sandbox.execute',
    ))->toThrow(ForbiddenHostCapabilityException::class);

    // 2. Docker socket / daemon manipulation attempt -> Blocked
    expect(fn () => $guard->validate(
        incident: $incident,
        arguments: ['workspace_id' => 'sbx-1', 'command' => 'docker run -v /:/host alpine'],
        toolName: 'sandbox.execute',
    ))->toThrow(ForbiddenHostCapabilityException::class);

    // 3. Namespace breakout / mount attempt -> Blocked
    expect(fn () => $guard->validate(
        incident: $incident,
        arguments: ['workspace_id' => 'sbx-1', 'command' => 'nsenter -t 1 -m -u -i -n -p -- sh'],
        toolName: 'sandbox.execute',
    ))->toThrow(ForbiddenHostCapabilityException::class);

    // Verify security audit log
    expect(AuditLog::where('event', 'security.sandbox_breakout_attempt')->count())->toBeGreaterThan(0);
});

test('MCPToolGateway halts breakout attempts before executing any code in container', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/app']);
    $workspaceId = app(DockerSandboxManager::class)->create($incident, 'node');

    // Attempt breakout command via Gateway
    expect(fn () => $gateway->execute(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.execute',
        arguments: [
            'workspace_id' => $workspaceId,
            'command' => 'sudo cat /etc/shadow',
        ],
        context: $incident,
    ))->toThrow(ForbiddenHostCapabilityException::class);

    // Safe command succeeds through Gateway
    $safeRes = $gateway->execute(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.execute',
        arguments: [
            'workspace_id' => $workspaceId,
            'command' => 'npm test -- --coverage',
        ],
        context: $incident,
    );

    expect($safeRes['success'])->toBeTrue()
        ->and($safeRes['data']['exit_code'])->toBe(0);
});
