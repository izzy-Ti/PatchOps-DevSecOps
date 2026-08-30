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

class ExecuteCommandTool implements ToolInterface
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
        return 'sandbox.execute';
    }

    public function description(): string
    {
        return 'Execute a test script, build command, or reproduction proof-of-concept inside an isolated sandbox container with hard timeouts.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'Target provisioned sandbox workspace ID.',
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'Command line string to execute in the container.',
                ],
                'timeout_seconds' => [
                    'type' => 'integer',
                    'description' => 'Execution timeout in seconds (default 600s).',
                    'default' => 600,
                ],
            ],
            'required' => ['workspace_id', 'command'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::SANDBOX_EXECUTE;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $workspaceId = $arguments['workspace_id'];
        $command = $arguments['command'];
        $timeout = isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : (isset($arguments['timeout']) ? (int) $arguments['timeout'] : null);

        $resultDto = $this->sandboxManager->execute($workspaceId, $command, $timeout);

        return $resultDto->toArray();
    }
}
