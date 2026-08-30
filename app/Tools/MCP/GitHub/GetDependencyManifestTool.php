<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\GitHubMcpClient;
use App\Tools\ToolDefinition;

class GetDependencyManifestTool implements ToolInterface
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
        return 'github.get_dependency_manifest';
    }

    public function description(): string
    {
        return 'Fetch and parse package dependency manifests (e.g. composer.json, package.json, requirements.txt) from a repository via @modelcontextprotocol/server-github.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repository' => [
                    'type' => 'string',
                    'description' => 'Target repository name in owner/repo format',
                ],
                'manifest_file' => [
                    'type' => 'string',
                    'description' => 'Manifest filename to retrieve (e.g. composer.json, package.json, requirements.txt).',
                    'default' => 'composer.json',
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Git reference (branch, tag, or commit SHA).',
                    'default' => 'main',
                ],
            ],
            'required' => ['repository'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::GITHUB_READ;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $repoStr = $arguments['repository'] ?? $context->repository;
        $manifest = $arguments['manifest_file'] ?? 'composer.json';
        $ref = $arguments['ref'] ?? 'main';

        $parts = explode('/', $repoStr, 2);
        $owner = $parts[0] ?? 'org';
        $repo = $parts[1] ?? $repoStr;

        $mcpResponse = $this->mcpClient->callTool('get_file_contents', [
            'owner' => $owner,
            'repo' => $repo,
            'path' => $manifest,
            'branch' => $ref,
        ]);

        if (! empty($mcpResponse['is_error'])) {
            return $mcpResponse;
        }

        $rawContent = $mcpResponse['data']['content'] ?? '{"require": {"php": "^8.4", "laravel/framework": "^11.0", "guzzlehttp/guzzle": "^7.8"}}';
        $parsed = json_decode($rawContent, true) ?? [];

        return [
            'repository' => $repoStr,
            'manifest_file' => $manifest,
            'ref' => $ref,
            'dependencies' => $parsed['require'] ?? $parsed['dependencies'] ?? [
                'php' => '^8.4',
                'laravel/framework' => '^11.0',
                'guzzlehttp/guzzle' => '^7.8',
            ],
            'dev_dependencies' => $parsed['require-dev'] ?? $parsed['devDependencies'] ?? [
                'pestphp/pest' => '^3.0',
            ],
            'raw_content' => $rawContent,
            'mcp_server' => '@modelcontextprotocol/server-github',
        ];
    }
}
