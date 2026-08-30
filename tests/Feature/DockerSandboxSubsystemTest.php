<?php

use App\Exceptions\MCP\UnauthorizedToolException;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\MCP\MCPToolGateway;
use App\Services\Sandbox\DockerSandboxManager;
use App\Tools\Enums\AgentRole;
use App\Tools\MCP\Sandbox\CreateEnvironmentTool;
use App\Tools\MCP\Sandbox\DestroyEnvironmentTool;
use App\Tools\MCP\Sandbox\ExecuteCommandTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('DockerSandboxManager completes full lifecycle (create, execute, destroy)', function () {
    $manager = app(DockerSandboxManager::class);
    $incident = Incident::factory()->create();

    // 1. Create
    $workspaceId = $manager->create($incident, 'node', ['NODE_ENV' => 'test']);
    expect($workspaceId)->toContain("sbx-{$incident->id}");

    $incident->refresh();
    expect($incident->metadata['sandbox_workspace_id'])->toBe($workspaceId);

    // 2. Execute
    $result = $manager->execute($workspaceId, 'npm --version', 30);
    expect($result->success)->toBeTrue()
        ->and($result->exitCode)->toBe(0)
        ->and($result->stdout)->toBeString()
        ->and($result->durationSeconds)->toBeGreaterThanOrEqual(0.0);

    // 3. Destroy
    $destroyed = $manager->destroy($workspaceId);
    expect($destroyed)->toBeTrue();
});

test('Sandbox MCP tools operate in harmony with incident metadata and audit trail', function () {
    $createTool = app(CreateEnvironmentTool::class);
    $execTool = app(ExecuteCommandTool::class);
    $destroyTool = app(DestroyEnvironmentTool::class);

    $incident = Incident::factory()->create();

    // Step 1: Create
    $createRes = $createTool->execute(['ecosystem' => 'python'], $incident);
    $workspaceId = $createRes['workspace_id'];

    expect($createRes['status'])->toBe('provisioned')
        ->and($createRes['ecosystem'])->toBe('python')
        ->and($createRes['read_only_root'])->toBeTrue();

    // Step 2: Execute
    $execRes = $execTool->execute([
        'workspace_id' => $workspaceId,
        'command' => 'pytest tests/test_vuln.py',
    ], $incident);

    expect($execRes['success'])->toBeTrue()
        ->and($execRes['exit_code'])->toBe(0);

    // Step 3: Destroy
    $destroyRes = $destroyTool->execute(['workspace_id' => $workspaceId], $incident);
    expect($destroyRes['status'])->toBe('destroyed')
        ->and($destroyRes['cleaned'])->toBeTrue();

    // Verify audit logs
    expect(AuditLog::where('event', 'sandbox.environment_provisioned')->count())->toBe(1)
        ->and(AuditLog::where('event', 'sandbox.environment_destroyed')->count())->toBe(1);
});

test('MCPToolGateway denies TriageAgent from executing sandbox tools and permits ReproductionAgent', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create();

    // 1. TriageAgent attempting to provision sandbox -> Unauthorized
    expect(fn () => $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'sandbox.create_environment',
        arguments: ['ecosystem' => 'node'],
        context: $incident,
    ))->toThrow(UnauthorizedToolException::class);

    // 2. ReproductionAgent provisioning sandbox -> Authorized
    $res = $gateway->execute(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.create_environment',
        arguments: ['ecosystem' => 'node'],
        context: $incident,
    );

    expect($res['success'])->toBeTrue()
        ->and($res['data']['status'])->toBe('provisioned');
});
