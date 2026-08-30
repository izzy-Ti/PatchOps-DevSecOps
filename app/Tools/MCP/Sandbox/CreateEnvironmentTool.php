<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Services\Sandbox\Contracts\SandboxManagerInterface;
use App\Services\Sandbox\DockerSandboxManager;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;

class CreateEnvironmentTool implements ToolInterface
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
            riskLevel: RiskLevel::MEDIUM,
        );
    }

    public function name(): string
    {
        return 'sandbox.create_environment';
    }

    public function description(): string
    {
        return 'Provision an isolated, disposable containerized sandbox environment with strict resource limits and network isolation.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'incident_id' => [
                    'type' => 'string',
                    'description' => 'Target incident ID or incident number.',
                ],
                'ecosystem' => [
                    'type' => 'string',
                    'enum' => ['node', 'python', 'php', 'go', 'ruby'],
                    'description' => 'Base runtime image ecosystem.',
                    'default' => 'node',
                ],
                'environment_vars' => [
                    'type' => 'object',
                    'description' => 'Non-sensitive key-value pairs to inject as environment variables.',
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
        $ecosystem = $arguments['ecosystem'] ?? 'node';
        $envVars = (array) ($arguments['environment_vars'] ?? []);

        $workspaceId = $this->sandboxManager->create($context, $ecosystem, $envVars);

        return [
            'workspace_id' => $workspaceId,
            'ecosystem' => $ecosystem,
            'status' => 'provisioned',
            'memory_limit' => config('sandbox.resources.memory_limit', '512m'),
            'network_mode' => config('sandbox.security.network_mode', 'none'),
            'user' => config('sandbox.security.user', '1000:1000'),
            'read_only_root' => true,
        ];
    }
}
