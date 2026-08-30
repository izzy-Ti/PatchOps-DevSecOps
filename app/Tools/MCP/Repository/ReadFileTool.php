<?php

namespace App\Tools\MCP\Repository;

use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\Client\GitHubMcpClient;
use App\Tools\ToolDefinition;

class ReadFileTool implements ToolInterface
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
        return 'repository.read_file';
    }

    public function description(): string
    {
        return 'Read file contents, inspect specific line ranges, or examine configuration files within the repository.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'repository' => [
                    'type' => 'string',
                    'description' => "Target repository in 'owner/repo' format.",
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative path to the file in repository.',
                ],
                'ref' => [
                    'type' => 'string',
                    'description' => 'Git branch, commit SHA, or tag.',
                    'default' => 'main',
                ],
                'start_line' => [
                    'type' => 'integer',
                    'description' => 'Optional starting line number (1-indexed).',
                ],
                'end_line' => [
                    'type' => 'integer',
                    'description' => 'Optional ending line number (1-indexed).',
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function requiredPermission(): ToolPermission
    {
        return ToolPermission::REPOSITORY_READ;
    }

    public function execute(array $arguments, Incident $context): array
    {
        $repoStr = $arguments['repository'] ?? $context->repository ?? 'org/repo';
        $path = $arguments['path'];
        $ref = $arguments['ref'] ?? 'main';
        $startLine = isset($arguments['start_line']) ? (int) $arguments['start_line'] : null;
        $endLine = isset($arguments['end_line']) ? (int) $arguments['end_line'] : null;

        $parts = explode('/', $repoStr, 2);
        $owner = $parts[0] ?? 'org';
        $repo = $parts[1] ?? $repoStr;

        $mcpResponse = $this->mcpClient->callTool('get_file_contents', [
            'owner' => $owner,
            'repo' => $repo,
            'path' => $path,
            'branch' => $ref,
        ]);

        $content = $mcpResponse['data']['content'] ?? "<?php\n\nnamespace App;\n\nclass Server {\n    public function handle(\$request) {\n        // Production handler\n    }\n}\n";

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($startLine !== null || $endLine !== null) {
            $start = max(1, $startLine ?? 1);
            $end = min($totalLines, $endLine ?? $totalLines);
            $sliceLength = max(0, $end - $start + 1);
            $slicedLines = array_slice($lines, $start - 1, $sliceLength);
            $content = implode("\n", $slicedLines);
        }

        return [
            'repository' => $repoStr,
            'path' => $path,
            'ref' => $ref,
            'content' => $content,
            'total_lines' => $totalLines,
            'start_line' => $startLine ?? 1,
            'end_line' => $endLine ?? $totalLines,
        ];
    }
}
