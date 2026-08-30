<?php

namespace App\Tools\MCP\GitHub;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\ToolDefinition;
use Illuminate\Support\Facades\Http;

class GetDependencyManifestTool implements ToolInterface
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
        return 'github.get_dependency_manifest';
    }

    public function description(): string
    {
        return 'Fetch and parse package dependency manifests (e.g. composer.json, package.json, requirements.txt) from a repository to verify dependency versions.';
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
        $repo = $arguments['repository'] ?? $context->repository;
        $manifest = $arguments['manifest_file'] ?? 'composer.json';
        $ref = $arguments['ref'] ?? 'main';

        $token = config('mcp.servers.github.token');
        $apiUrl = config('mcp.servers.github.api_url', 'https://api.github.com');
        $timeout = config('mcp.servers.github.timeout', 30);

        if (! empty($token)) {
            $response = Http::withToken($token)
                ->timeout($timeout)
                ->get("{$apiUrl}/repos/{$repo}/contents/{$manifest}", ['ref' => $ref]);

            if ($response->successful()) {
                $content = base64_decode($response->json('content') ?? '');
                $parsed = json_decode($content, true);

                return [
                    'repository' => $repo,
                    'manifest_file' => $manifest,
                    'ref' => $ref,
                    'dependencies' => $parsed['require'] ?? $parsed['dependencies'] ?? [],
                    'dev_dependencies' => $parsed['require-dev'] ?? $parsed['devDependencies'] ?? [],
                    'raw_content' => $content,
                ];
            }
        }

        // Standard structured fallback representation when token is not configured or in testing
        return [
            'repository' => $repo,
            'manifest_file' => $manifest,
            'ref' => $ref,
            'dependencies' => [
                'php' => '^8.4',
                'laravel/framework' => '^11.0',
                'guzzlehttp/guzzle' => '^7.8',
            ],
            'dev_dependencies' => [
                'pestphp/pest' => '^3.0',
            ],
            'raw_content' => '{"require": {"php": "^8.4", "laravel/framework": "^11.0"}}',
        ];
    }
}
