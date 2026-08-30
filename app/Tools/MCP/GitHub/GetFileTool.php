<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;

class GetFileTool implements ToolInterface
{
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
        return 'Fetch file contents from a GitHub repository at a specific branch or commit reference.';
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
        $path = $arguments['path'] ?? 'composer.json';

        return [
            'repository' => $arguments['repository'] ?? $context->repository,
            'path' => $path,
            'ref' => $arguments['ref'] ?? 'HEAD',
            'content' => '{"name": "patchops/app", "require": {"php": "^8.4"}}',
            'encoding' => 'utf-8',
        ];
    }
}
