<?php

namespace App\Tools\MCP\Repository;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Permissions\ToolPermission;

class InspectStructureTool implements ToolInterface
{
    public function name(): string
    {
        return 'repository_inspect_structure';
    }

    public function description(): string
    {
        return 'List directory structure, source files, and test files within a repository directory.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'directory' => [
                    'type' => 'string',
                    'description' => 'Subdirectory path to inspect (defaults to root .).',
                    'default' => '.',
                ],
            ],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::REPOSITORY_READ;
    }

    public function execute(array $arguments, Incident $context): array
    {
        return [
            'directory' => $arguments['directory'] ?? '.',
            'files' => ['src/Security.php', 'composer.json', 'tests/SecurityTest.php'],
        ];
    }
}
