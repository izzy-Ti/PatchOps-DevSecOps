<?php

use App\Models\Incident;
use App\Models\Vulnerability;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use App\Tools\MCP\Repository\InspectDependenciesTool;
use App\Tools\MCP\Repository\InspectStructureTool;
use App\Tools\MCP\Repository\ReadFileTool;
use App\Tools\MCP\Repository\SearchCodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('InspectStructureTool retrieves repository directory hierarchy and file list', function () {
    $tool = app(InspectStructureTool::class);
    $incident = Incident::factory()->create(['repository' => 'acme/express-service']);

    $result = $tool->execute(['repository' => 'acme/express-service'], $incident);

    expect($result['repository'])->toBe('acme/express-service')
        ->and($result['directory'])->toBe('.')
        ->and($result['structure'])->toBeArray()
        ->and($result['entry_count'])->toBeGreaterThan(0);
});

test('ReadFileTool reads and slices file contents with line boundaries', function () {
    $tool = app(ReadFileTool::class);
    $incident = Incident::factory()->create(['repository' => 'acme/express-service']);

    $result = $tool->execute([
        'repository' => 'acme/express-service',
        'path' => 'package.json',
        'start_line' => 1,
        'end_line' => 3,
    ], $incident);

    expect($result['path'])->toBe('package.json')
        ->and($result['start_line'])->toBe(1)
        ->and($result['end_line'])->toBe(3)
        ->and($result['content'])->toBeString();
});

test('SearchCodeTool finds code usages and evaluates production exposure', function () {
    $tool = app(SearchCodeTool::class);
    $incident = Incident::factory()->create(['repository' => 'acme/express-service']);

    $result = $tool->execute([
        'repository' => 'acme/express-service',
        'pattern' => 'express-query-parser',
    ], $incident);

    expect($result['pattern'])->toBe('express-query-parser')
        ->and($result['matches'])->toBeArray()
        ->and($result['match_count'])->toBeGreaterThan(0)
        ->and($result['production_exposure_detected'])->toBeTrue();
});

test('InspectDependenciesTool inspects manifest and determines direct production exposure', function () {
    $tool = app(InspectDependenciesTool::class);
    $vuln = Vulnerability::factory()->create(['package_name' => 'express-query-parser']);
    $incident = Incident::factory()->create([
        'repository' => 'acme/express-service',
        'vulnerability_id' => $vuln->id,
    ]);

    $result = $tool->execute([
        'repository' => 'acme/express-service',
        'target_package' => 'express-query-parser',
    ], $incident);

    expect($result['target_package'])->toBe('express-query-parser')
        ->and($result['installed_version'])->toBe('1.4.1')
        ->and($result['is_production_dependency'])->toBeTrue()
        ->and($result['exposure_assessment'])->toBe('DIRECT_PRODUCTION_EXPOSURE');
});

test('Triage Agent conducts full 4-step repository inspection pipeline via MCPToolGateway', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/express-service']);

    // Step 1: Inspect Structure
    $res1 = $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'repository.inspect_structure',
        arguments: ['repository' => 'acme/express-service'],
        context: $incident,
    );
    expect($res1['success'])->toBeTrue()
        ->and($res1['data']['repository'])->toBe('acme/express-service');

    // Step 2: Inspect Dependencies
    $res2 = $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'repository.inspect_dependencies',
        arguments: [
            'repository' => 'acme/express-service',
            'target_package' => 'express',
        ],
        context: $incident,
    );
    expect($res2['success'])->toBeTrue()
        ->and($res2['data']['target_package'])->toBe('express');

    // Step 3: Read Manifest File
    $res3 = $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'repository.read_file',
        arguments: [
            'repository' => 'acme/express-service',
            'path' => 'package.json',
        ],
        context: $incident,
    );
    expect($res3['success'])->toBeTrue()
        ->and($res3['data']['path'])->toBe('package.json');

    // Step 4: Search Code
    $res4 = $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'repository.search_code',
        arguments: [
            'repository' => 'acme/express-service',
            'pattern' => 'require("express")',
        ],
        context: $incident,
    );
    expect($res4['success'])->toBeTrue()
        ->and($res4['data']['pattern'])->toBe('require("express")')
        ->and($res4['data']['production_exposure_detected'])->toBeTrue();
});
