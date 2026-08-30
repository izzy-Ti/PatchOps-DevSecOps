<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;

class GetRepositoryTool implements ToolInterface
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
        return 'github.get_repository';
    }

    public function description(): string
    {
        return 'Fetch metadata, default branch, language, and settings for a GitHub repository.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repository' => [
                    'type' => 'string',
                    'description' => 'Target repository name in owner/repo format (e.g. laravel/framework).',
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
        $repo = $arguments['repository'] ?? $context->repository;

        return [
            'repository' => $repo,
            'default_branch' => 'main',
            'language' => 'PHP',
            'status' => 'accessible',
        ];
    }
}
