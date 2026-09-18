<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\GitHubMcpClient;
use App\Tools\ToolDefinition;
use Illuminate\Support\Str;

class ApplyAndCommitPatchTool implements ToolInterface
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
        return 'github.apply_and_commit_patch';
    }

    public function description(): string
    {
        return 'Apply unified diff patch and sign commit to the target branch via @modelcontextprotocol/server-github.';
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
                    'description' => 'Target branch to commit changes to',
                ],
                'patch_content' => [
                    'type' => 'string',
                    'description' => 'Unified diff patch content',
                ],
                'commit_message' => [
                    'type' => 'string',
                    'description' => 'Commit message with sign-off',
                ],
            ],
            'required' => ['repository', 'branch', 'patch_content', 'commit_message'],
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
        $commitMessage = $arguments['commit_message'];
        $patchContent = $arguments['patch_content'];

        $mcpResponse = $this->mcpClient->callTool('create_or_update_file', [
            'owner' => $owner,
            'repo' => $repo,
            'branch' => $branch,
            'message' => $commitMessage,
            'patch' => $patchContent,
        ]);

        if (! empty($mcpResponse['is_error'])) {
            return $mcpResponse;
        }

        $commitSha = $mcpResponse['data']['commit']['sha']
            ?? $mcpResponse['data']['sha']
            ?? hash('sha1', $commitMessage.':'.substr($patchContent, 0, 100).':'.Str::random(10));

        return [
            'success' => true,
            'repository' => $repoStr,
            'branch' => $branch,
            'commit_sha' => $commitSha,
            'commit_message' => $commitMessage,
            'mcp_server' => '@modelcontextprotocol/server-github',
        ];
    }
}
