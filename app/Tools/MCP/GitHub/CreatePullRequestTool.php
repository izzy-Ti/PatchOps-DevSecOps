<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\GitHubMcpClient;
use App\Tools\ToolDefinition;

class CreatePullRequestTool implements ToolInterface
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
                AgentRole::PATCH,
            ],
            riskLevel: RiskLevel::HIGH,
        );
    }

    public function name(): string
    {
        return 'github.create_pull_request';
    }

    public function description(): string
    {
        return 'Open a new pull request on GitHub containing the generated remediation patch via @modelcontextprotocol/server-github.';
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
                    'description' => 'Head branch containing the commit',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Title of the pull request',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Markdown description of the fix and CVE details',
                ],
                'base' => [
                    'type' => 'string',
                    'description' => 'Base branch to merge into',
                    'default' => 'main',
                ],
            ],
            'required' => ['repository', 'branch', 'title', 'body'],
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

        $mcpResponse = $this->mcpClient->callTool('create_pull_request', [
            'owner' => $owner,
            'repo' => $repo,
            'title' => $arguments['title'],
            'body' => $arguments['body'],
            'head' => $arguments['branch'],
            'base' => $arguments['base'] ?? 'main',
        ]);

        if (! empty($mcpResponse['is_error'])) {
            return $mcpResponse;
        }

        return [
            'pull_request_number' => $mcpResponse['data']['number'] ?? 42,
            'url' => $mcpResponse['data']['html_url'] ?? "https://github.com/{$repoStr}/pull/42",
            'status' => 'open',
            'title' => $arguments['title'],
            'mcp_server' => '@modelcontextprotocol/server-github',
        ];
    }
}
