<?php

use App\Models\Incident;
use App\Models\ToolExecution;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use App\Tools\MCP\Client\SandboxMcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SandboxMcpClient dispatches container lifecycle tools cleanly', function () {
    $client = app(SandboxMcpClient::class);

    // 1. Create sandbox
    $createRes = $client->createSandbox('INC-0001', 'node20', ['NODE_ENV' => 'test']);
    expect($createRes['success'])->toBeTrue();

    $sandboxId = $createRes['sandbox_id'] ?? 'sbx-INC-0001-test';

    // 2. Clone repository
    $cloneRes = $client->cloneRepository($sandboxId, 'acme/webapp', 'main');
    expect($cloneRes['success'])->toBeTrue();

    // 3. Install dependencies
    $installRes = $client->installDependencies($sandboxId, 'npm', ['--silent']);
    expect($installRes['success'])->toBeTrue();

    // 4. Execute command
    $execRes = $client->executeCommand($sandboxId, 'npm test', 60);
    expect($execRes['success'])->toBeTrue()
        ->and($execRes['exit_code'])->toBe(0);

    // 5. Collect logs
    $logsRes = $client->collectLogs($sandboxId, 50);
    expect($logsRes['success'])->toBeTrue()
        ->and($logsRes['stdout'])->not->toBeNull();

    // 6. Destroy sandbox
    $destroyRes = $client->destroySandbox($sandboxId);
    expect($destroyRes['success'])->toBeTrue();
});

test('MCPToolGateway executes dedicated sandbox tools for Reproduction and Validation roles', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    // 1. Reproduction Agent provisions sandbox
    $createResult = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.create_environment',
        arguments: [
            'incident_id' => $incident->incident_number,
            'ecosystem' => 'node',
        ],
        context: $incident,
    );

    expect($createResult['success'])->toBeTrue();
    $sandboxId = $createResult['data']['workspace_id'] ?? 'sbx-test-01';

    // 2. Clone repository via sandbox.clone_repository
    $cloneResult = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.clone_repository',
        arguments: [
            'sandbox_id' => $sandboxId,
            'repository' => 'acme/webapp',
            'ref' => 'main',
        ],
        context: $incident,
    );

    expect($cloneResult['success'])->toBeTrue();

    // 3. Install dependencies via sandbox.install_dependencies
    $installResult = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.install_dependencies',
        arguments: [
            'sandbox_id' => $sandboxId,
            'package_manager' => 'npm',
        ],
        context: $incident,
    );

    expect($installResult['success'])->toBeTrue();

    // 4. Execute command via sandbox.execute
    $execResult = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.execute',
        arguments: [
            'workspace_id' => $sandboxId,
            'command' => 'npm run test:poc',
        ],
        context: $incident,
    );

    expect($execResult['success'])->toBeTrue();

    // 5. Collect logs via sandbox.collect_logs
    $logsResult = $gateway->invoke(
        role: AgentRole::VALIDATION,
        toolName: 'sandbox.collect_logs',
        arguments: [
            'sandbox_id' => $sandboxId,
            'tail_lines' => 100,
        ],
        context: $incident,
    );

    expect($logsResult['success'])->toBeTrue();

    // 6. Destroy sandbox via sandbox.destroy_environment
    $destroyResult = $gateway->invoke(
        role: AgentRole::VALIDATION,
        toolName: 'sandbox.destroy_environment',
        arguments: [
            'workspace_id' => $sandboxId,
        ],
        context: $incident,
    );

    expect($destroyResult['success'])->toBeTrue();

    // Verify executions logged
    expect(ToolExecution::where('incident_id', $incident->id)->count())->toBe(6);
});
