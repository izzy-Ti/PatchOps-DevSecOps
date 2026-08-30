<?php

use App\Enums\IncidentStatus;
use App\Exceptions\MCP\HitlApprovalRequiredException;
use App\Exceptions\MCP\UnauthorizedToolException;
use App\Models\AgentRun;
use App\Models\Incident;
use App\Models\ToolExecution;
use App\Services\MCP\Guards\HitlApprovalGuard;
use App\Services\MCP\MCPPermissionService;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('MCPToolGateway logs every tool invocation in tool_executions with status and duration', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);
    $agentRun = AgentRun::create([
        'incident_id' => $incident->id,
        'agent_type' => 'triage',
        'status' => 'running',
    ]);

    // Execute authorized read tool
    $res = $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'vulnerability.get_cve',
        arguments: ['cve_id' => 'CVE-2026-1001'],
        context: $incident,
        agentRunId: $agentRun->id,
    );

    expect($res['success'])->toBeTrue();

    // Verify ToolExecution record
    $execution = ToolExecution::where('incident_id', $incident->id)->first();
    expect($execution)->not->toBeNull()
        ->and($execution->tool_name)->toBe('vulnerability.get_cve')
        ->and($execution->status)->toBe('success')
        ->and($execution->risk_level)->toBe('low')
        ->and($execution->agent_run_id)->toBe($agentRun->id)
        ->and($execution->duration_ms)->toBeGreaterThan(0)
        ->and($execution->completed_at)->not->toBeNull();

    // Verify Eloquent relationships
    expect($incident->toolExecutions()->count())->toBe(1)
        ->and($agentRun->toolExecutions()->count())->toBe(1);
});

test('MCPToolGateway records DENIED in tool_executions when permission check fails', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    // Triage agent tries to invoke write PR tool -> Unauthorized
    expect(fn () => $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'github.create_pull_request',
        arguments: [
            'repository' => 'acme/webapp',
            'branch' => 'patch/cve-fix',
            'title' => 'Fix',
            'body' => 'Body',
        ],
        context: $incident,
    ))->toThrow(UnauthorizedToolException::class);

    $execution = ToolExecution::where('incident_id', $incident->id)->first();
    expect($execution)->not->toBeNull()
        ->and($execution->tool_name)->toBe('github.create_pull_request')
        ->and($execution->status)->toBe('denied');
});

test('HitlApprovalGuard intercepts CRITICAL tools, marks pending_approval, and transitions incident status', function () {
    $registry = new ToolRegistry;
    $incident = Incident::factory()->create([
        'repository' => 'acme/webapp',
        'status' => IncidentStatus::TRIAGING,
    ]);

    // Register a dynamic CRITICAL tool
    $criticalTool = new class implements ToolInterface
    {
        public function definition(): ToolDefinition
        {
            return new ToolDefinition(
                name: 'production.deploy',
                description: 'Deploy patch to production',
                inputSchema: ['type' => 'object'],
                requiredPermission: ToolPermission::GITHUB_WRITE,
                allowedAgents: [AgentRole::PATCH],
                riskLevel: RiskLevel::CRITICAL,
            );
        }

        public function name(): string
        {
            return 'production.deploy';
        }

        public function description(): string
        {
            return 'Deploy';
        }

        public function parametersSchema(): array
        {
            return ['type' => 'object'];
        }

        public function requiredPermission(): ToolPermission
        {
            return ToolPermission::GITHUB_WRITE;
        }

        public function execute(array $arguments, Incident $context): array
        {
            return ['status' => 'deployed_to_prod'];
        }
    };

    $registry->register($criticalTool);
    $permissionService = new MCPPermissionService($registry);
    $gateway = new MCPToolGateway(
        permissionService: $permissionService,
        registry: $registry,
    );

    // 1. Autonomous execution without approval -> Intercepted and incident transitioned
    expect(fn () => $gateway->execute(
        role: AgentRole::PATCH,
        toolName: 'production.deploy',
        arguments: [],
        context: $incident,
    ))->toThrow(HitlApprovalRequiredException::class);

    // Assert status changed to AWAITING_APPROVAL
    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::AWAITING_APPROVAL);

    // Assert tool_executions recorded pending_approval
    $exec = ToolExecution::where('incident_id', $incident->id)->where('tool_name', 'production.deploy')->first();
    expect($exec)->not->toBeNull()
        ->and($exec->status)->toBe('pending_approval');

    // 2. Execution with valid HMAC Approval Token -> Succeeds
    $token = HitlApprovalGuard::generateApprovalToken($incident, 'production.deploy');

    $res = $gateway->execute(
        role: AgentRole::PATCH,
        toolName: 'production.deploy',
        arguments: ['approval_token' => $token],
        context: $incident,
    );

    expect($res['success'])->toBeTrue()
        ->and($res['data']['status'])->toBe('deployed_to_prod');

    // Assert second tool execution marked success
    $successExec = ToolExecution::where('incident_id', $incident->id)
        ->where('tool_name', 'production.deploy')
        ->where('status', 'success')
        ->first();
    expect($successExec)->not->toBeNull();
});

test('MCPToolGateway redacts secret tokens from arguments and responses', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    $res = $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'vulnerability.get_cve',
        arguments: [
            'cve_id' => 'CVE-2026-1001',
            'api_token' => 'secret_live_token_12345',
        ],
        context: $incident,
    );

    $execution = ToolExecution::where('incident_id', $incident->id)->first();
    expect($execution->arguments['api_token'])->toBe('***REDACTED***');
});
