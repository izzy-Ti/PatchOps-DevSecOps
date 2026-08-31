<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Services\Sandbox\DTOs\DependencyInstallResultDTO;
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
        return 'Automatically detect repository manifest (package.json, composer.json, requirements.txt, go.mod) and execute safe, whitelisted, bounded dependency installation.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sandbox_id' => [
                    'type' => 'string',
                    'description' => 'Opaque sandbox identifier (e.g., sb_01KABC...).',
                ],
                'manifest_path' => [
                    'type' => 'string',
                    'description' => 'Optional relative path to the manifest directory (defaults to /app).',
                ],
            ],
            'required' => ['sandbox_id'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::SANDBOX_EXECUTE;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $sandboxId = (string) $arguments['sandbox_id'];
        $manifestPath = isset($arguments['manifest_path']) ? (string) $arguments['manifest_path'] : null;

        $rawResult = $this->client->installDependencies($sandboxId, $manifestPath);

        return DependencyInstallResultDTO::fromArray($rawResult)->toArray();
    }
}
