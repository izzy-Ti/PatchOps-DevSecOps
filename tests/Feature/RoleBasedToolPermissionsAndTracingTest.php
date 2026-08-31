<?php

use App\Exceptions\MCP\UnauthorizedToolException;
use App\Models\Incident;
use App\Models\SandboxExecution;
use App\Services\MCP\Guards\ToolPermissionGuard;
use App\Services\MCP\MCPToolGateway;
use App\Services\Tracing\TraceContext;
use App\Tools\Enums\AgentRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ToolPermissionGuard prevents Triage Agent from executing sandbox tools', function () {
    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::TRIAGE, 'sandbox.create_environment'))
        ->toThrow(UnauthorizedToolException::class);

    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::TRIAGE, 'sandbox.execute'))
        ->toThrow(UnauthorizedToolException::class);

    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::TRIAGE, 'sandbox.clone_repository'))
        ->toThrow(UnauthorizedToolException::class);
});

test('ToolPermissionGuard permits Reproduction, Patch, and Validation agents to execute sandbox tools', function () {
    // Reproduction
    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::REPRODUCTION, 'sandbox.create_environment'))
        ->not->toThrow(UnauthorizedToolException::class);
    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::REPRODUCTION, 'sandbox.execute'))
        ->not->toThrow(UnauthorizedToolException::class);

    // Patch
    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::PATCH, 'sandbox.create_environment'))
        ->not->toThrow(UnauthorizedToolException::class);
    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::PATCH, 'workspace.write_patch'))
        ->not->toThrow(UnauthorizedToolException::class);

    // Validation
    expect(fn () => ToolPermissionGuard::assertPermission(AgentRole::VALIDATION, 'sandbox.execute'))
        ->not->toThrow(UnauthorizedToolException::class);
});

test('TraceContext manages hierarchical correlation trace attributes', function () {
    TraceContext::clear();

    TraceContext::set(
        correlationId: 'corr_test_01',
        incidentId: 'INC-0001',
        agentRunId: 'AR-0042',
        sandboxId: 'sb_01KABC',
        agentRole: 'reproduction'
    );

    $trace = TraceContext::toArray();

    expect($trace['correlation_id'])->toBe('corr_test_01')
        ->and($trace['incident_id'])->toBe('INC-0001')
        ->and($trace['agent_run_id'])->toBe('AR-0042')
        ->and($trace['sandbox_id'])->toBe('sb_01KABC')
        ->and($trace['agent_role'])->toBe('reproduction');
});

test('MCPToolGateway injects hierarchical correlation IDs and records SandboxExecution telemetry', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create([
        'repository' => 'acme/webapp',
        'correlation_id' => 'corr_inc_999',
    ]);

    $sandboxId = "sbx-{$incident->id}";
    $agentRunId = 123;

    // Execute command via gateway
    $result = $gateway->execute(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.execute',
        arguments: [
            'workspace_id' => $sandboxId,
            'command' => 'npm test --filter=SecurityTest',
        ],
        context: $incident,
        agentRunId: $agentRunId,
    );

    expect($result['success'])->toBeTrue();

    // Verify SandboxExecution database telemetry record
    $execution = SandboxExecution::where('incident_id', $incident->id)->first();
    expect($execution)->not->toBeNull()
        ->and($execution->sandbox_id)->toBe($sandboxId)
        ->and($execution->agent_run_id)->toBe('123')
        ->and($execution->correlation_id)->toBe('corr_inc_999')
        ->and($execution->command)->toBe('npm test --filter=SecurityTest')
        ->and($execution->exit_code)->toBe(0);
});

test('MCPToolGateway blocks Triage Agent from calling sandbox tools with standardized error response', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    $response = $gateway->invoke(
        role: AgentRole::TRIAGE,
        toolName: 'sandbox.create_environment',
        arguments: ['incident_id' => $incident->incident_number],
        context: $incident,
    );

    expect($response['success'])->toBeFalse()
        ->and($response['error']['code'])->toBe('PERMISSION_DENIED')
        ->and($response['error']['message'])->toContain('Agent role [triage] is unauthorized');
});
