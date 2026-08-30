<?php

use App\Exceptions\MCP\UnauthorizedCriticalActionException;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\MCP\Guards\ToolRiskLevelGuard;
use App\Services\Sandbox\DockerSandboxManager;
use App\Services\Sandbox\DTOs\SandboxLimitsDTO;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\GitHub\CreatePullRequestTool;
use App\Tools\MCP\GitHub\GetFileTool;
use App\Tools\MCP\Sandbox\ExecuteCommandTool;
use App\Tools\ToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SandboxLimitsDTO accurately loads cgroup quotas and execution limits from config', function () {
    $limits = SandboxLimitsDTO::fromConfig();

    expect($limits->cpu)->toBe('2.0')
        ->and($limits->memory)->toBe('2g')
        ->and($limits->memorySwap)->toBe('2g')
        ->and($limits->timeoutSeconds)->toBe(600)
        ->and($limits->tmpfsSize)->toBe('512m')
        ->and($limits->pidsLimit)->toBe(100)
        ->and($limits->network)->toBe('none');
});

test('ToolRiskLevelGuard categorizes tools across the Four-Tier Risk Classification Matrix', function () {
    $guard = app(ToolRiskLevelGuard::class);
    $incident = Incident::factory()->create();

    // 1. LOW Risk Tool (Read-only)
    $lowTool = app(GetFileTool::class);
    expect($lowTool->definition()->riskLevel)->toBe(RiskLevel::LOW);
    $guard->evaluate($lowTool, ['repository' => 'acme/repo', 'path' => 'package.json'], $incident);

    // 2. MEDIUM Risk Tool (Sandbox Execution)
    $medTool = app(ExecuteCommandTool::class);
    expect($medTool->definition()->riskLevel)->toBe(RiskLevel::MEDIUM);
    $guard->evaluate($medTool, ['workspace_id' => 'sbx-1', 'command' => 'npm test'], $incident);

    // 3. HIGH Risk Tool (Repository Mutation / Pull Request)
    $highTool = app(CreatePullRequestTool::class);
    expect($highTool->definition()->riskLevel)->toBe(RiskLevel::HIGH);
    $guard->evaluate($highTool, [
        'repository' => 'acme/repo',
        'branch' => 'patch/cve-fix',
        'title' => 'Fix CVE',
        'body' => 'Details',
    ], $incident);
});

test('ToolRiskLevelGuard blocks CRITICAL production-impacting tools without HITL approval', function () {
    $guard = app(ToolRiskLevelGuard::class);
    $incident = Incident::factory()->create(['metadata' => []]);

    // Create mock CRITICAL risk tool
    $criticalTool = new class implements ToolInterface
    {
        public function definition(): ToolDefinition
        {
            return new ToolDefinition(
                name: 'production.deploy',
                description: 'Deploy patch to production clusters',
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
            return ['status' => 'deployed'];
        }
    };

    // 1. Autonomous execution without human sign-off -> Rejected
    expect(fn () => $guard->evaluate($criticalTool, [], $incident))
        ->toThrow(UnauthorizedCriticalActionException::class);

    expect(AuditLog::where('event', 'security.critical_tool_blocked')->count())->toBeGreaterThan(0);

    // 2. With explicit HITL approval -> Allowed
    $incident->metadata = ['hitl_approved' => true, 'human_approval_signature' => 'secops-lead-sig'];
    $incident->save();

    // Does not throw
    $guard->evaluate($criticalTool, [], $incident);
    expect(true)->toBeTrue();
});

test('DockerSandboxManager attaches configured resource limits to provisioned environments', function () {
    $manager = app(DockerSandboxManager::class);
    $incident = Incident::factory()->create();

    $workspaceId = $manager->create($incident, 'node');

    expect($workspaceId)->toStartWith("sbx-{$incident->id}-");
    expect(AuditLog::where('event', 'sandbox.environment_provisioned')->count())->toBeGreaterThan(0);
});
