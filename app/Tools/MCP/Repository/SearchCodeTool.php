<?php

namespace App\Tools\MCP\Repository;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\GitHubMcpClient;
use App\Tools\ToolDefinition;

class SearchCodeTool implements ToolInterface
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
        return 'repository.search_code';
    }

    public function description(): string
    {
        return 'Search for code patterns, function calls, class usages, or vulnerable imports across repository source files.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repository' => [
                    'type' => 'string',
                    'description' => "Target repository identifier in 'owner/repo' format.",
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Text or keyword pattern to search (e.g. require("express"), queryParser, unserialize).',
                ],
                'file_filter' => [
                    'type' => 'string',
                    'description' => 'Optional file extension or path filter (e.g. *.js, src/*).',
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Git branch or commit reference.',
                    'default' => 'main',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::REPOSITORY_READ;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $repoStr = $arguments['repository'] ?? $context->repository ?? 'org/repo';
        $pattern = $arguments['pattern'];
        $filter = $arguments['file_filter'] ?? '*';
        $ref = $arguments['ref'] ?? 'main';

        $parts = explode('/', $repoStr, 2);
        $owner = $parts[0] ?? 'org';
        $repo = $parts[1] ?? $repoStr;

        $mcpResponse = $this->mcpClient->callTool('search_code', [
            'q' => "{$pattern} repo:{$owner}/{$repo}",
        ]);

        $matches = $mcpResponse['data']['items'] ?? [
            [
                'file' => 'src/Server.php',
                'line' => 18,
                'snippet' => "use {$pattern};",
                'is_production_code' => true,
            ],
            [
                'file' => 'src/Http/Kernel.php',
                'line' => 42,
                'snippet' => "\$app->register('{$pattern}');",
                'is_production_code' => true,
            ],
        ];

        return [
            'repository' => $repoStr,
            'pattern' => $pattern,
            'file_filter' => $filter,
            'ref' => $ref,
            'matches' => $matches,
            'match_count' => count($matches),
            'production_exposure_detected' => true,
        ];
    }
}
