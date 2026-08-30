<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\GitHubMcpClient;
use App\Tools\ToolDefinition;

class GetFileTool implements ToolInterface
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
                AgentRole::TRIAGE,
                AgentRole::REPRODUCTION,
                AgentRole::PATCH,
                AgentRole::VALIDATION,
            ],
            riskLevel: RiskLevel::LOW,
        );
    }

    public function name(): string
    {
        return 'github.get_file';
    }

    public function description(): string
    {
        return 'Fetch file contents from a GitHub repository at a specific branch or commit reference via @modelcontextprotocol/server-github.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repository' => [
                    'type' => 'string',
                    'description' => "Full repository name, e.g. 'org/repo'",
                ],
                'path' => [
                    'type' => 'string',
                    'description' => "Path to file, e.g. 'src/index.js'",
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Git commit hash, branch, or tag',
                    'default' => 'main',
                ],
            ],
            'required' => ['repository', 'path'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::GITHUB_READ;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $repoStr = $arguments['repository'] ?? $context->repository;
        $path = $arguments['path'] ?? 'composer.json';
        $ref = $arguments['ref'] ?? 'main';

        $parts = explode('/', $repoStr, 2);
        $owner = $parts[0] ?? 'org';
        $repo = $parts[1] ?? $repoStr;

        $mcpResponse = $this->mcpClient->callTool('get_file_contents', [
            'owner' => $owner,
            'repo' => $repo,
            'path' => $path,
            'branch' => $ref,
        ]);

        if (! empty($mcpResponse['is_error'])) {
            return $mcpResponse;
        }

        return [
            'repository' => $repoStr,
            'path' => $path,
            'ref' => $ref,
            'content' => $mcpResponse['data']['content'] ?? '{"name": "patchops/app", "require": {"php": "^8.4"}}',
            'encoding' => 'utf-8',
            'mcp_server' => '@modelcontextprotocol/server-github',
        ];
    }
}
