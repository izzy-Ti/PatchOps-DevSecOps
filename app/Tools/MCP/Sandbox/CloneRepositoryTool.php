<?php

namespace App\Tools\MCP\Sandbox;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\SandboxMcpClient;
use App\Tools\ToolDefinition;

class CloneRepositoryTool implements ToolInterface
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
        return 'sandbox.clone_repository';
    }

    public function description(): string
    {
        return 'Mount or clone target repository snapshot into the isolated container workspace (/app).';
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
                'repository' => [
                    'type' => 'string',
                    'description' => 'Target repository name in owner/repo format.',
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Git branch, tag, or commit SHA to checkout.',
                    'default' => 'main',
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
        $repo = (string) ($arguments['repository'] ?? $context->repository);
        $ref = (string) ($arguments['ref'] ?? 'main');

        return $this->client->cloneRepository($sandboxId, $repo, $ref);
    }
}
