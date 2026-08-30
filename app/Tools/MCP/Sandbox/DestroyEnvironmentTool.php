<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Services\AuditLogger;
use App\Services\Sandbox\Contracts\SandboxManagerInterface;
use App\Services\Sandbox\DockerSandboxManager;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;

class DestroyEnvironmentTool implements ToolInterface
{
    public function __construct(
        protected ?SandboxManagerInterface $sandboxManager = null,
    ) {
        $this->sandboxManager ??= app(DockerSandboxManager::class);
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
            riskLevel: RiskLevel::LOW,
        );
    }

    public function name(): string
    {
        return 'sandbox.destroy_environment';
    }

    public function description(): string
    {
        return 'Force-kill container processes, purge ephemeral filesystem volumes, and destroy the sandbox environment.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'Target sandbox workspace ID to destroy.',
                ],
            ],
            'required' => ['workspace_id'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::SANDBOX_DESTROY;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $workspaceId = $arguments['workspace_id'];

        $this->sandboxManager->destroy($workspaceId);

        AuditLogger::logSystemAction(
            event: 'sandbox.environment_destroyed',
            auditable: $context,
            payload: [
                'workspace_id' => $workspaceId,
            ],
            correlationId: $context->correlation_id,
        );

        return [
            'workspace_id' => $workspaceId,
            'status' => 'destroyed',
            'cleaned' => true,
        ];
    }
}
