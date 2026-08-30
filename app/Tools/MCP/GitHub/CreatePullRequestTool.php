<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Permissions\ToolPermission;

class CreatePullRequestTool implements ToolInterface
{
    public function name(): string
    {
        return 'github_create_pull_request';
    }

    public function description(): string
    {
        return 'Create an official Pull Request on GitHub containing security patch modifications (Requires human approval).';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Pull request title.',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Detailed PR description with patch diagnostics and CVE reference.',
                ],
                'branch' => [
                    'type' => 'string',
                    'description' => 'Head branch name for the fix.',
                ],
                'base' => [
                    'type' => 'string',
                    'description' => 'Base branch to merge into (e.g. main).',
                    'default' => 'main',
                ],
            ],
            'required' => ['title', 'body', 'branch'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::GITHUB_WRITE;
    }

    public function execute(array $arguments, Incident $context): array
    {
        return [
            'pull_request_number' => 42,
            'url' => "https://github.com/{$context->repository}/pull/42",
            'status' => 'open',
            'title' => $arguments['title'],
        ];
    }
}
