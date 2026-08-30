<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Services\Sandbox\SandboxManagerInterface;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Permissions\ToolPermission;

class ExecuteCommandTool implements ToolInterface
{
    public function __construct(
        protected ?SandboxManagerInterface $sandbox = null,
    ) {
        $this->sandbox ??= app(SandboxManagerInterface::class);
    }

    public function name(): string
    {
        return 'sandbox_execute';
    }

    public function description(): string
    {
        return 'Execute a shell command inside an isolated ephemeral Docker container with execution limits.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'The target sandbox workspace identifier.',
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'Shell command to execute inside the sandbox.',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Execution ceiling in seconds.',
                    'default' => 60,
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
        $timeout = $arguments['timeout'] ?? 60;

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
