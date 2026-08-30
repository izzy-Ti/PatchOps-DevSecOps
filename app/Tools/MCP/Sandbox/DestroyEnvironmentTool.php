<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Services\Sandbox\SandboxManagerInterface;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Permissions\ToolPermission;

class DestroyEnvironmentTool implements ToolInterface
{
    public function __construct(
        protected ?SandboxManagerInterface $sandbox = null,
    ) {
        $this->sandbox ??= app(SandboxManagerInterface::class);
    }

    public function name(): string
    {
        return 'sandbox_destroy_environment';
    }

    public function description(): string
    {
        return 'Tear down and destroy an isolated ephemeral Docker container workspace.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'workspace_id' => [
                    'type' => 'string',
                    'description' => 'The ephemeral sandbox workspace identifier to destroy.',
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
        $this->sandbox->cleanup($workspaceId);

        return [
            'workspace_id' => $workspaceId,
            'status' => 'destroyed',
        ];
    }
}
