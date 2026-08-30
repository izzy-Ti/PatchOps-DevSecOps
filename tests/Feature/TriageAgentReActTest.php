<?php

use App\Agents\TriageAgent;
use App\DTOs\AgentErrorDTO;
use App\DTOs\AgentResultDTO;
use App\Enums\IncidentStatus;
use App\Models\AgentRun;
use App\Models\Incident;
use App\Models\ToolExecution;
use App\Models\Vulnerability;
use App\Services\MCP\MCPToolGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.anthropic.key', 'test-anthropic-key');
    config()->set('patchops.max_triage_steps', 5);
});

test('TriageAgent conducts multi-step ReAct investigation using MCP tools and aggregates evidence', function () {
    $vuln = Vulnerability::factory()->create([
        'cve_id' => 'CVE-2026-4444',
        'package_name' => 'express',
        'affected_version' => '< 4.19.2',
        'fixed_version' => '4.19.2',
    ]);

    $incident = Incident::factory()->create([
        'vulnerability_id' => $vuln->id,
        'repository' => 'acme/express-api',
        'status' => IncidentStatus::TRIAGING,
    ]);

    $agentRun = AgentRun::create([
        'incident_id' => $incident->id,
        'agent_type' => 'triage',
        'status' => 'running',
    ]);

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::sequence()
            // Turn 1: Claude queries advisory intelligence
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Let me first query the vulnerability advisory details for CVE-2026-4444.',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'call_triage_01',
                        'name' => 'vulnerability.get_cve',
                        'input' => ['cve_id' => 'CVE-2026-4444'],
                    ],
                ],
            ], 200)
            // Turn 2: Claude inspects dependency manifest
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Advisory retrieved. Now let me inspect dependencies to verify express is in production dependencies.',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'call_triage_02',
                        'name' => 'repository.inspect_dependencies',
                        'input' => ['repository' => 'acme/express-api'],
                    ],
                ],
            ], 200)
            // Turn 3: Claude searches code for vulnerable API usage
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Express 4.18.2 found. Let me search the codebase for express router and body parser usage.',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'call_triage_03',
                        'name' => 'repository.search_code',
                        'input' => [
                            'repository' => 'acme/express-api',
                            'query' => 'express.json',
                        ],
                    ],
                ],
            ], 200)
            // Turn 4: Claude concludes investigation with record_triage_analysis
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'call_triage_04',
                        'name' => 'record_triage_analysis',
                        'input' => [
                            'severity' => 'critical',
                            'priority' => 'critical',
                            'production_exposed' => true,
                            'affected_component' => 'express',
                            'reason' => 'Active production exposure confirmed. express 4.18.2 is mounted directly in API routes and vulnerable to prototype pollution.',
                            'evidence_summary' => 'Vulnerability advisory, package.json dependencies, and source code usage verified.',
                        ],
                    ],
                ],
            ], 200),
    ]);

    $agent = app(TriageAgent::class);
    $result = $agent->analyze($incident, $agentRun->id);

    expect($result)->toBeInstanceOf(AgentResultDTO::class)
        ->and($result->success)->toBeTrue()
        ->and($result->data['severity'])->toBe('critical')
        ->and($result->data['priority'])->toBe('critical')
        ->and($result->data['production_exposed'])->toBeTrue()
        ->and($result->data['affected_component'])->toBe('express')
        ->and($result->metadata['react_steps'])->toBe(4)
        ->and($result->metadata['observations_count'])->toBe(3);

    // Verify incident metadata holds aggregated evidence
    $incident->refresh();
    expect($incident->metadata['triage_evidence'])->toHaveKeys([
        'vulnerability.get_cve',
        'repository.inspect_dependencies',
        'repository.search_code',
    ]);
    expect($incident->metadata['triage_result']['affected_component'])->toBe('express');

    // Verify ToolExecution records were logged through MCPToolGateway
    expect(ToolExecution::where('incident_id', $incident->id)->count())->toBe(3);
});

test('TriageAgent handles tool errors gracefully and continues ReAct loop', function () {
    $incident = Incident::factory()->create([
        'repository' => 'acme/express-api',
        'status' => IncidentStatus::TRIAGING,
    ]);

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::sequence()
            // Turn 1: Claude asks for non-existent file -> Error caught and passed as tool_result
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'call_err_01',
                        'name' => 'repository.read_file',
                        'input' => [
                            'repository' => 'acme/express-api',
                            'file_path' => 'non_existent_file.json',
                        ],
                    ],
                ],
            ], 200)
            // Turn 2: Claude adapts and concludes
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'call_err_02',
                        'name' => 'record_triage_analysis',
                        'input' => [
                            'severity' => 'medium',
                            'priority' => 'low',
                            'production_exposed' => false,
                            'affected_component' => 'dev-tools',
                            'reason' => 'Manifest was not found, classified as low priority dev tool.',
                        ],
                    ],
                ],
            ], 200),
    ]);

    $agent = app(TriageAgent::class);
    $result = $agent->analyze($incident);

    expect($result->success)->toBeTrue()
        ->and($result->data['severity'])->toBe('medium')
        ->and($result->metadata['react_steps'])->toBe(2);
});

test('TriageAgent aborts when max_triage_steps is exceeded without conclusion', function () {
    config()->set('patchops.max_triage_steps', 2);

    $incident = Incident::factory()->create([
        'repository' => 'acme/express-api',
        'status' => IncidentStatus::TRIAGING,
    ]);

    // Claude keeps looping without calling record_triage_analysis
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'stop_reason' => 'tool_use',
            'content' => [
                [
                    'type' => 'tool_use',
                    'id' => 'call_loop',
                    'name' => 'vulnerability.get_cve',
                    'input' => ['cve_id' => 'CVE-2026-9999'],
                ],
            ],
        ], 200),
    ]);

    $agent = app(TriageAgent::class);
    $result = $agent->analyze($incident);

    expect($result->success)->toBeFalse()
        ->and($result->error?->code)->toBe(AgentErrorDTO::MAX_ATTEMPTS_EXCEEDED)
        ->and($result->error?->message)->toContain('Triage Agent exceeded maximum ReAct investigation steps');
});
