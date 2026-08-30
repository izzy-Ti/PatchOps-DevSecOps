<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Services\Sandbox\SandboxManagerInterface;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;
use Illuminate\Support\Str;

class CreateEnvironmentTool implements ToolInterface
{
    public function __construct(
        protected ?SandboxManagerInterface $sandbox = null,
    ) {
        $this->sandbox ??= app(SandboxManagerInterface::class);
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: $this->name(),
            description: $this->description(),
            inputSchema: $this->parametersSchema(),
            requiredPermission: $this->requiredPermission(),
            allowedAgents: [
                AgentRole::REPRODUCTION,
                AgentRole::VALIDATION,
            ],
            riskLevel: RiskLevel::MEDIUM,
        );
    }

    public function name(): string
    {
        return 'sandbox.create_environment';
    }

    public function description(): string
    {
        return 'Provision an isolated ephemeral Docker container workspace for reproduction or validation tests.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workspace_prefix' => [
                    'type' => 'string',
                    'description' => 'Optional name prefix for the ephemeral workspace identifier.',
                    'default' => 'agent-sandbox',
                ],
            ],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::SANDBOX_PROVISION;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $prefix = $arguments['workspace_prefix'] ?? 'agent-sandbox';
        $workspaceId = "{$prefix}-".Str::random(8);

        $path = $this->sandbox->createWorkspace($workspaceId);

        return [
            'workspace_id' => $workspaceId,
            'path' => $path,
            'status' => 'ready',
        ];
    }
}
