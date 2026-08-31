<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\SandboxMcpClient;
use App\Tools\ToolDefinition;

class CollectLogsTool implements ToolInterface
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
            riskLevel: RiskLevel::LOW,
        );
    }

    public function name(): string
    {
        return 'sandbox.collect_logs';
    }

    public function description(): string
    {
        return 'Retrieve aggregated output logs and performance metrics from the container workspace.';
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
                'tail_lines' => [
                    'type' => 'number',
                    'description' => 'Number of tail log lines to retrieve.',
                    'default' => 100,
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
        $tailLines = (int) ($arguments['tail_lines'] ?? 100);

        return $this->client->collectLogs($sandboxId, $tailLines);
    }
}
