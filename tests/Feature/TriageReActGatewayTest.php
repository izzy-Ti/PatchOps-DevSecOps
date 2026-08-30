<?php

use App\Agents\TriageAgent;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Vulnerability;
use App\Tools\Enums\AgentRole;
use App\Tools\Gateway\PatchOpsToolGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config()->set('services.anthropic.key', 'test-anthropic-key');
});

test('PatchOpsToolGateway authorizes allowed tools and rejects unauthorized tools with structured error', function () {
    $gateway = app(PatchOpsToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'laravel/framework']);

    // 1. Authorized call (github.get_repository for Triage role)
    $authResult = $gateway->invokeTool(
        toolName: 'github.get_repository',
        arguments: ['repository' => 'laravel/framework'],
        role: AgentRole::TRIAGE,
        incident: $incident,
    );

    expect($authResult['success'])->toBeTrue()
        ->and($authResult['is_error'])->toBeFalse()
        ->and($authResult['data']['repository'])->toBe('laravel/framework');

    // Audit log created
    expect(AuditLog::where('event', 'tool_gateway.invocation')->count())->toBe(1);

    // 2. Unauthorized call (sandbox.execute for Triage role)
    $unauthResult = $gateway->invokeTool(
        toolName: 'sandbox.execute',
        arguments: ['workspace_id' => 'ws-1', 'command' => 'php -v'],
        role: AgentRole::TRIAGE,
        incident: $incident,
    );

    expect($unauthResult['success'])->toBeFalse()
        ->and($unauthResult['is_error'])->toBeTrue()
        ->and($unauthResult['error'])->toContain('Agent role [triage] is unauthorized to execute tool [sandbox.execute]');
});

test('TriageAgent executes multi-turn ReAct investigation loop chaining GitHub MCP tools', function () {
    $vuln = Vulnerability::factory()->create([
        'cve_id' => 'CVE-2026-1111',
        'package_name' => 'guzzlehttp/guzzle',
    ]);
    $incident = Incident::factory()->create([
        'vulnerability_id' => $vuln->id,
        'repository' => 'acme/billing-api',
    ]);

    // Mock Anthropic Multi-Turn ReAct responses
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::sequence()
            // Turn 1: Claude asks for repository info
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Let me first inspect the repository metadata.',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'call_1',
                        'name' => 'github.get_repository',
                        'input' => ['repository' => 'acme/billing-api'],
                    ],
                ],
            ], 200)
            // Turn 2: Claude asks for dependency manifest
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Repository found. Now let me check composer.json to verify if guzzlehttp/guzzle is in runtime dependencies.',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'call_2',
                        'name' => 'github.get_dependency_manifest',
                        'input' => ['repository' => 'acme/billing-api', 'manifest_file' => 'composer.json'],
                    ],
                ],
            ], 200)
            // Turn 3: Claude submits final triage verdict
            ->push([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'call_3',
                        'name' => 'record_triage_analysis',
                        'input' => [
                            'severity' => 'high',
                            'priority' => 'critical',
                            'production_exposed' => true,
                            'affected_component' => 'guzzlehttp/guzzle',
                            'reason' => 'Confirmed guzzlehttp/guzzle ^7.8 is in runtime composer.json require block and handles payment webhooks.',
                        ],
                    ],
                ],
            ], 200),
    ]);

    $agent = app(TriageAgent::class);
    $result = $agent->analyze($incident);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and($result->data['severity'])->toBe('high')
        ->and($result->data['priority'])->toBe('critical')
        ->and($result->data['production_exposed'])->toBeTrue()
        ->and($result->data['affected_component'])->toBe('guzzlehttp/guzzle')
        ->and($result->metadata['react_steps'])->toBe(3);
});
