<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\SandboxMcpClient;
use App\Tools\ToolDefinition;

class InstallDependenciesTool implements ToolInterface
{
    public function __construct(
        protected ?SandboxMcpClient $client = null,
    ) {
        $this->client ??= app(SandboxMcpClient::class);
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
        return 'sandbox.install_dependencies';
    }

    public function description(): string
    {
        return 'Execute bounded package dependency installation inside the container workspace.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sandbox_id' => [
                    'type' => 'string',
                    'description' => 'Target sandbox container ID.',
                ],
                'package_manager' => [
                    'type' => 'string',
                    'enum' => ['npm', 'composer', 'pip', 'yarn', 'pnpm'],
                    'description' => 'Ecosystem package manager to invoke.',
                ],
                'flags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional CLI flags for dependency installation.',
                ],
            ],
            'required' => ['sandbox_id', 'package_manager'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::SANDBOX_EXECUTE;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $sandboxId = (string) $arguments['sandbox_id'];
        $pm = (string) $arguments['package_manager'];
        $flags = (array) ($arguments['flags'] ?? []);

        return $this->client->installDependencies($sandboxId, $pm, $flags);
    }
}
