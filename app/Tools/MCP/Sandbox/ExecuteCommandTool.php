<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Services\Sandbox\SandboxManagerInterface;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;

class ExecuteCommandTool implements ToolInterface
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
            riskLevel: RiskLevel::CRITICAL,
        );
    }

    public function name(): string
    {
        return 'sandbox.execute';
    }

    public function description(): string
    {
        return 'Execute a shell command inside an ephemeral container and return stdout, stderr, and exit code.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'Unique identifier of the provisioned sandbox',
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'Shell command string to run inside the container',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Maximum execution time in seconds',
                    'default' => 120,
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
        $timeout = $arguments['timeout'] ?? 120;

        $result = $this->sandbox->runCommand($workspaceId, $command, $timeout);

        return [
            'success' => $result->success,
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'execution_time_seconds' => $result->executionTimeSeconds,
        ];
    }
}
