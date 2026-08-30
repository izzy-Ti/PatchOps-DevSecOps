<?php

use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\RiskLevel;
use App\Tools\Enums\ToolPermission;
use App\Tools\MCP\GitHub\CreatePullRequestTool;
use App\Tools\MCP\GitHub\GetFileTool;
use App\Tools\MCP\GitHub\GetRepositoryTool;
use App\Tools\MCP\Repository\InspectStructureTool;
use App\Tools\MCP\Repository\SearchCodeTool;
use App\Tools\MCP\Sandbox\CreateEnvironmentTool;
use App\Tools\MCP\Sandbox\DestroyEnvironmentTool;
use App\Tools\MCP\Sandbox\ExecuteCommandTool;
use App\Tools\MCP\Vulnerability\GetCveTool;
use App\Tools\MCP\Vulnerability\SearchVulnerabilityTool;
use App\Tools\ToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ToolDefinition encapsulates all 6 metadata attributes correctly', function () {
    $definition = new ToolDefinition(
        name: 'github.get_file',
        description: 'Fetch file contents from a GitHub repository.',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'repository' => ['type' => 'string'],
                'path' => ['type' => 'string'],
            ],
            'required' => ['repository', 'path'],
        ],
        requiredPermission: ToolPermission::GITHUB_READ,
        allowedAgents: [
            AgentRole::TRIAGE,
            AgentRole::REPRODUCTION,
            AgentRole::PATCH,
            AgentRole::VALIDATION,
        ],
        riskLevel: RiskLevel::LOW,
    );

    expect($definition->name)->toBe('github.get_file')
        ->and($definition->description)->toBe('Fetch file contents from a GitHub repository.')
        ->and($definition->requiredPermission)->toBe(ToolPermission::GITHUB_READ)
        ->and($definition->riskLevel)->toBe(RiskLevel::LOW)
        ->and($definition->isAllowedFor(AgentRole::TRIAGE))->toBeTrue()
        ->and($definition->isAllowedFor(AgentRole::PATCH))->toBeTrue()
        ->and($definition->isHighRisk())->toBeFalse();

    $llmSchema = $definition->toLlmToolSchema();
    expect($llmSchema)->toHaveKeys(['name', 'description', 'input_schema'])
        ->and($llmSchema['name'])->toBe('github.get_file')
        ->and($llmSchema['input_schema'])->toHaveKey('properties');
});

test('ToolDefinition classifies high and critical risk tools correctly', function () {
    $highRiskDef = new ToolDefinition(
        name: 'github.create_pull_request',
        description: 'Open PR',
        inputSchema: [],
        requiredPermission: ToolPermission::GITHUB_WRITE,
        allowedAgents: [AgentRole::PATCH],
        riskLevel: RiskLevel::HIGH,
    );

    $criticalRiskDef = new ToolDefinition(
        name: 'sandbox.execute',
        description: 'Run container command',
        inputSchema: [],
        requiredPermission: ToolPermission::SANDBOX_EXECUTE,
        allowedAgents: [AgentRole::REPRODUCTION, AgentRole::VALIDATION],
        riskLevel: RiskLevel::CRITICAL,
    );

    $lowRiskDef = new ToolDefinition(
        name: 'repository.read_file',
        description: 'Read file',
        inputSchema: [],
        requiredPermission: ToolPermission::REPOSITORY_READ,
        allowedAgents: [AgentRole::TRIAGE],
        riskLevel: RiskLevel::LOW,
    );

    expect($highRiskDef->isHighRisk())->toBeTrue()
        ->and($criticalRiskDef->isHighRisk())->toBeTrue()
        ->and($lowRiskDef->isHighRisk())->toBeFalse();
});

test('All registered MCP tools implement ToolInterface and declare complete ToolDefinition metadata', function () {
    $tools = [
        new GetRepositoryTool,
        new GetFileTool,
        new CreatePullRequestTool,
        new GetCveTool,
        new SearchVulnerabilityTool,
        new InspectStructureTool,
        new SearchCodeTool,
        new CreateEnvironmentTool,
        new ExecuteCommandTool,
        new DestroyEnvironmentTool,
    ];

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(ToolInterface::class);

        $def = $tool->definition();
        expect($def)->toBeInstanceOf(ToolDefinition::class)
            ->and($def->name)->not->toBeEmpty()
            ->and($def->description)->not->toBeEmpty()
            ->and($def->inputSchema)->toBeArray()
            ->and($def->requiredPermission)->toBeInstanceOf(ToolPermission::class)
            ->and($def->allowedAgents)->not->toBeEmpty()
            ->and($def->riskLevel)->toBeInstanceOf(RiskLevel::class);
    }
});
