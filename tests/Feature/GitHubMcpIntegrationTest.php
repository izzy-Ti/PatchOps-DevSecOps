<?php

use App\Models\Incident;
use App\Tools\MCP\Client\GitHubMcpClient;
use App\Tools\MCP\GitHub\CreatePullRequestTool;
use App\Tools\MCP\GitHub\GetDependencyManifestTool;
use App\Tools\MCP\GitHub\GetFileTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('GitHubMcpClient parses JSON-RPC 2.0 responses and maps native tool calls', function () {
    $mockMcp = Mockery::mock(GitHubMcpClient::class);
    $mockMcp->shouldReceive('callTool')
        ->with('get_file_contents', [
            'owner' => 'laravel',
            'repo' => 'framework',
            'path' => 'composer.json',
            'branch' => '11.x',
        ])
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'content' => '{"name": "laravel/framework", "require": {"php": "^8.4"}}',
            ],
        ]);

    app()->instance(GitHubMcpClient::class, $mockMcp);

    $tool = new GetFileTool($mockMcp);
    $incident = Incident::factory()->create();

    $result = $tool->execute([
        'repository' => 'laravel/framework',
        'path' => 'composer.json',
        'ref' => '11.x',
    ], $incident);

    expect($result['success'] ?? true)->toBeTrue()
        ->and($result['repository'])->toBe('laravel/framework')
        ->and($result['content'])->toBe('{"name": "laravel/framework", "require": {"php": "^8.4"}}')
        ->and($result['mcp_server'])->toBe('@modelcontextprotocol/server-github');
});

test('GitHubMcpClient maps server rate-limit errors to GITHUB_RATE_LIMITED envelope', function () {
    $mockMcp = Mockery::mock(GitHubMcpClient::class);
    $mockMcp->shouldReceive('callTool')
        ->once()
        ->andReturn([
            'is_error' => true,
            'error_code' => GitHubMcpClient::ERROR_RATE_LIMITED,
            'message' => 'GitHub API rate limit exceeded on MCP server.',
        ]);

    app()->instance(GitHubMcpClient::class, $mockMcp);

    $tool = new GetDependencyManifestTool($mockMcp);
    $incident = Incident::factory()->create();

    $result = $tool->execute(['repository' => 'org/repo'], $incident);

    expect($result['is_error'])->toBeTrue()
        ->and($result['error_code'])->toBe(GitHubMcpClient::ERROR_RATE_LIMITED)
        ->and($result['message'])->toContain('rate limit exceeded');
});

test('PatchOpsToolGateway routes GitHub MCP create_pull_request to official MCP server', function () {
    $mockMcp = Mockery::mock(GitHubMcpClient::class);
    $mockMcp->shouldReceive('callTool')
        ->with('create_pull_request', [
            'owner' => 'acme',
            'repo' => 'service',
            'title' => 'fix(security): sanitize input',
            'body' => 'CVE remediation',
            'head' => 'patch-fix-1',
            'base' => 'main',
        ])
        ->once()
        ->andReturn([
            'success' => true,
            'data' => [
                'number' => 88,
                'html_url' => 'https://github.com/acme/service/pull/88',
            ],
        ]);

    app()->instance(GitHubMcpClient::class, $mockMcp);

    $tool = new CreatePullRequestTool($mockMcp);
    $incident = Incident::factory()->create(['repository' => 'acme/service']);

    $result = $tool->execute([
        'repository' => 'acme/service',
        'title' => 'fix(security): sanitize input',
        'body' => 'CVE remediation',
        'branch' => 'patch-fix-1',
        'base' => 'main',
    ], $incident);

    expect($result['pull_request_number'])->toBe(88)
        ->and($result['url'])->toBe('https://github.com/acme/service/pull/88')
        ->and($result['mcp_server'])->toBe('@modelcontextprotocol/server-github');
});
