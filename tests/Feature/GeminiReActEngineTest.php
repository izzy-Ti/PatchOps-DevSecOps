<?php

use App\Agents\PatchAgent;
use App\Agents\ReproductionAgent;
use App\Agents\ReviewerAgent;
use App\Agents\TriageAgent;
use App\DTOs\ReviewerContextDTO;
use App\Models\Incident;
use App\Services\MCP\GeminiReActEngine;
use App\Services\MCP\MCPToolGateway;
use App\Services\MCP\ToolSchemaRegistry;
use App\Tools\Enums\AgentRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('ToolSchemaRegistry converts MCP tool parameters to Gemini uppercase types', function () {
    $registry = app(ToolSchemaRegistry::class);

    $mcpTool = [
        'name' => 'execute_command',
        'description' => 'Executes a command inside sandbox',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Shell command to run',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout in seconds',
                ],
                'env' => [
                    'type' => 'object',
                    'properties' => [
                        'DEBUG' => ['type' => 'boolean'],
                    ],
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['command'],
        ],
    ];

    $formatted = $registry->formatForGemini([$mcpTool]);

    expect($formatted)->toHaveCount(1)
        ->and($formatted[0]['name'])->toBe('execute_command')
        ->and($formatted[0]['description'])->toBe('Executes a command inside sandbox')
        ->and($formatted[0]['parameters']['type'])->toBe('OBJECT')
        ->and($formatted[0]['parameters']['properties']['command']['type'])->toBe('STRING')
        ->and($formatted[0]['parameters']['properties']['timeout']['type'])->toBe('INTEGER')
        ->and($formatted[0]['parameters']['properties']['env']['type'])->toBe('OBJECT')
        ->and($formatted[0]['parameters']['properties']['env']['properties']['DEBUG']['type'])->toBe('BOOLEAN')
        ->and($formatted[0]['parameters']['properties']['tags']['type'])->toBe('ARRAY')
        ->and($formatted[0]['parameters']['properties']['tags']['items']['type'])->toBe('STRING')
        ->and($formatted[0]['parameters']['required'])->toBe(['command']);
});

test('GeminiReActEngine executes multi-turn ReAct loop with intermediate tool and terminal verdict', function () {
    config(['services.gemini.api_key' => 'fake-gemini-key']);
    config(['services.gemini.model' => 'gemini-1.5-pro-latest']);

    $incident = Incident::factory()->create([
        'incident_number' => 'INC-GEMINI-REACT',
        'title' => 'Prototype Pollution in Merge',
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::sequence()
            // Turn 1: Gemini decides to call sandbox.create
            ->push([
                'candidates' => [
                    [
                        'content' => [
                            'role' => 'model',
                            'parts' => [
                                [
                                    'functionCall' => [
                                        'name' => 'sandbox.create',
                                        'args' => [
                                            'workspace_id' => 'ws-gemini-123',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
            ], 200)
            // Turn 2: Gemini receives function response and submits final verdict
            ->push([
                'candidates' => [
                    [
                        'content' => [
                            'role' => 'model',
                            'parts' => [
                                [
                                    'functionCall' => [
                                        'name' => 'record_review_verdict',
                                        'args' => [
                                            'approved' => true,
                                            'verdict_summary' => 'Gemini successfully validated patch with zero regressions.',
                                            'vulnerability_mitigated' => true,
                                            'regressions_detected' => false,
                                            'build_passed' => true,
                                            'checks' => [
                                                [
                                                    'check_name' => 'PoC Mitigation Check',
                                                    'command' => 'npm run test:security',
                                                    'exit_code' => 1,
                                                    'passed' => true,
                                                    'details' => 'Exploit payload blocked.',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
            ], 200),
    ]);

    $mockGateway = Mockery::mock(MCPToolGateway::class);
    $mockGateway->shouldReceive('invoke')
        ->once()
        ->with(
            AgentRole::REVIEWER,
            'sandbox.create',
            ['workspace_id' => 'ws-gemini-123'],
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn([
            'sandbox_id' => 'sb-created-456',
            'status' => 'running',
        ]);

    $mockGateway->shouldReceive('invoke')
        ->once()
        ->with(
            AgentRole::REVIEWER,
            'record_review_verdict',
            Mockery::any(),
            Mockery::any(),
            Mockery::any()
        )
        ->andReturn(['status' => 'recorded']);

    $engine = new GeminiReActEngine(gateway: $mockGateway);

    $verdict = $engine->run(
        role: AgentRole::REVIEWER,
        systemInstruction: 'You are an adversarial reviewer.',
        context: ['incident_id' => $incident->id, 'cve_identifier' => 'CVE-2026-9999'],
        tools: ['sandbox.create', 'record_review_verdict'],
        maxIterations: 5,
        incident: $incident,
    );

    expect($verdict)->toBeArray()
        ->and($verdict['approved'])->toBeTrue()
        ->and($verdict['verdict_summary'])->toContain('Gemini successfully validated patch')
        ->and($verdict['vulnerability_mitigated'])->toBeTrue();

    Http::assertSentCount(2);
});

test('GeminiReActEngine executes deterministic fallback when API key is missing', function () {
    config(['services.gemini.api_key' => null]);

    $engine = app(GeminiReActEngine::class);

    $verdict = $engine->run(
        role: AgentRole::REVIEWER,
        systemInstruction: 'Review candidate patch.',
        context: ['cve_identifier' => 'CVE-FALLBACK-TEST'],
    );

    expect($verdict['approved'])->toBeTrue()
        ->and($verdict['vulnerability_mitigated'])->toBeTrue()
        ->and($verdict['regressions_detected'])->toBeFalse()
        ->and($verdict['checks'])->toHaveCount(2);
});

test('ReviewerAgent utilizes GeminiReActEngine when GEMINI_API_KEY is configured', function () {
    config(['services.gemini.api_key' => 'fake-gemini-key']);
    config(['services.gemini.model' => 'gemini-1.5-pro-latest']);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'record_review_verdict',
                                    'args' => [
                                        'approved' => true,
                                        'verdict_summary' => 'Audit passed with full compliance.',
                                        'vulnerability_mitigated' => true,
                                        'regressions_detected' => false,
                                        'build_passed' => true,
                                        'checks' => [
                                            [
                                                'check_name' => 'Exploit Mitigation Gate',
                                                'command' => 'npm run exploit',
                                                'exit_code' => 1,
                                                'passed' => true,
                                                'details' => 'Exploit blocked',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ], 200),
    ]);

    $context = ReviewerContextDTO::fromArray([
        'incident_id' => 'INC-REVIEWER-GEMINI',
        'cve_identifier' => 'CVE-2026-3321',
        'repository' => 'vendor/repo',
        'patch_candidate_diff' => "--- a/src/App.php\n+++ b/src/App.php\n+ return true;\n",
    ]);

    $agent = app(ReviewerAgent::class);
    $result = $agent->execute($context);

    expect($result->approved)->toBeTrue()
        ->and($result->vulnerabilityMitigated)->toBeTrue()
        ->and($result->verdictSummary)->toBe('Audit passed with full compliance.')
        ->and($result->checks)->toHaveCount(1);
});

test('PatchAgent synthesizes patch via Gemini function calling when GEMINI_API_KEY is active', function () {
    config(['services.gemini.api_key' => 'fake-gemini-key']);
    config(['services.gemini.model' => 'gemini-1.5-pro-latest']);

    $incident = Incident::factory()->create([
        'incident_number' => 'INC-PATCH-GEMINI',
        'title' => 'Remote Code Execution in YAML Parser',
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'record_patch_synthesis',
                                    'args' => [
                                        'root_cause' => 'Unsafe yaml_parse call without flags.',
                                        'fix_summary' => 'Applied safe_yaml mode and added regression test.',
                                        'diff' => "--- a/src/Parser.php\n+++ b/src/Parser.php\n- yaml_parse(\$str);\n+ yaml_parse(\$str, YAML_PARSE_STRICT);\n",
                                        'changed_files' => ['src/Parser.php'],
                                        'tests_added' => ['tests/ParserTest.php'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ], 200),
    ]);

    $agent = new PatchAgent;
    $result = $agent->generatePatch($incident);

    expect($result->success)->toBeTrue()
        ->and($result->data['root_cause'])->toBe('Unsafe yaml_parse call without flags.')
        ->and($result->data['changed_files'])->toContain('src/Parser.php')
        ->and($result->metadata['engine'])->toBe('gemini');
});

test('ReproductionAgent runs ReAct loop using GeminiReActEngine when GEMINI_API_KEY is active', function () {
    config(['services.gemini.api_key' => 'fake-gemini-key']);
    config(['services.gemini.model' => 'gemini-1.5-pro-latest']);

    $incident = Incident::factory()->create([
        'incident_number' => 'INC-REPRO-GEMINI',
        'title' => 'SSRF in Webhook Client',
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'record_reproduction_result',
                                    'args' => [
                                        'reproduced' => true,
                                        'command' => 'python exploit_ssrf.py',
                                        'exit_code' => 0,
                                        'stdout' => 'Captured response from internal metadata service 169.254.169.254',
                                        'stderr' => '',
                                        'duration_ms' => 350.5,
                                        'observations' => ['SSRF confirmed on cloud metadata IP.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ], 200),
    ]);

    $agent = app(ReproductionAgent::class);
    $result = $agent->reproduce($incident);

    expect($result->success)->toBeTrue()
        ->and($result->data['reproduced'])->toBeTrue()
        ->and($result->data['command'])->toBe('python exploit_ssrf.py')
        ->and($result->metadata['engine'])->toBe('gemini');
});

test('TriageAgent conducts ReAct analysis using GeminiReActEngine when GEMINI_API_KEY is active', function () {
    config(['services.gemini.api_key' => 'fake-gemini-key']);
    config(['services.gemini.model' => 'gemini-1.5-pro-latest']);

    $incident = Incident::factory()->create([
        'incident_number' => 'INC-TRIAGE-GEMINI',
        'title' => 'SQL Injection in Search Query',
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'record_triage_analysis',
                                    'args' => [
                                        'severity' => 'critical',
                                        'priority' => 'critical',
                                        'production_exposed' => true,
                                        'affected_component' => 'SearchService',
                                        'reason' => 'Direct string concatenation in raw SQL clause.',
                                        'evidence_summary' => 'Identified unparameterized search input.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ], 200),
    ]);

    $agent = app(TriageAgent::class);
    $result = $agent->analyze($incident);

    expect($result->success)->toBeTrue()
        ->and($result->data['severity'])->toBe('critical')
        ->and($result->data['production_exposed'])->toBeTrue()
        ->and($result->metadata['engine'])->toBe('gemini');
});
