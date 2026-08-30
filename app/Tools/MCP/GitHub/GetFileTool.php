<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Permissions\ToolPermission;

class GetFileTool implements ToolInterface
{
    public function name(): string
    {
        return 'github_get_file';
    }

    public function description(): string
    {
        return 'Fetch content of a source code file or manifest from a GitHub repository.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative file path in the repository (e.g. composer.json, src/Auth.php).',
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Git branch, tag, or commit SHA reference.',
                ],
            ],
            'required' => ['path'],
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
            'path' => $path,
            'ref' => $arguments['ref'] ?? 'HEAD',
            'content' => '{"name": "patchops/app", "require": {"php": "^8.4"}}',
            'encoding' => 'utf-8',
        ];
    }
}
