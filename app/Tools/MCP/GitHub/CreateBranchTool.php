<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\GitHubMcpClient;
use App\Tools\ToolDefinition;

class CreateBranchTool implements ToolInterface
{
    public function __construct(
        protected ?GitHubMcpClient $mcpClient = null,
    ) {
        $this->mcpClient ??= app(GitHubMcpClient::class);
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: $this->name(),
            description: $this->description(),
            inputSchema: $this->parametersSchema(),
            requiredPermission: $this->requiredPermission(),
            allowedAgents: [
                AgentRole::POST_APPROVAL,
                AgentRole::ORCHESTRATOR,
            ],

            riskLevel: RiskLevel::HIGH,
        );
    }

    public function name(): string
    {
        return 'github.create_branch';
    }

    public function description(): string
    {
        return 'Create an isolated remediation branch from target commit or base ref via @modelcontextprotocol/server-github.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repository' => [
                    'type' => 'string',
                    'description' => 'Target repository name',
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'Name of the new branch to create',
                ],
                'from_ref' => [
                    'type' => 'string',
                    'description' => 'Base commit SHA or branch reference',
                ],
            ],
            'required' => ['repository', 'branch', 'from_ref'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::GITHUB_WRITE;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $repoStr = $arguments['repository'] ?? $context->repository;
        $parts = explode('/', $repoStr, 2);
        $owner = $parts[0] ?? 'org';
        $repo = $parts[1] ?? $repoStr;
        $branch = $arguments['branch'];
        $fromRef = $arguments['from_ref'];

        $mcpResponse = $this->mcpClient->callTool('create_branch', [
            'owner' => $owner,
            'repo' => $repo,
            'branch' => $branch,
            'from_ref' => $fromRef,
        ]);

        if (! empty($mcpResponse['is_error'])) {
            return $mcpResponse;
        }

        return [
            'success' => true,
            'repository' => $repoStr,
            'branch' => $branch,
            'from_ref' => $fromRef,
            'ref' => "refs/heads/{$branch}",
            'mcp_server' => '@modelcontextprotocol/server-github',
        ];
    }
}
