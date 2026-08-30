<?php

namespace App\Tools\MCP\Repository;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;

class SearchCodeTool implements ToolInterface
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
        return 'repository.search_code';
    }

    public function description(): string
    {
        return 'Search for code patterns, function calls, class declarations, or variables in the codebase.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Text or regex search pattern.',
                ],
                'file_filter' => [
                    'type' => 'string',
                    'description' => 'Optional glob pattern to restrict file scope (e.g. *.php).',
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
        return [
            'pattern' => $arguments['pattern'],
            'matches' => [
                ['file' => 'src/Security.php', 'line' => 24, 'content' => 'public function sanitize($input)'],
            ],
        ];
    }
}
