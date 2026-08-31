<?php

use App\Agents\ReproductionAgent;
use App\DTOs\ReproductionResultDTO;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Sandbox;
use App\Services\Reproduction\ReproductionWorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('Sandbox model tracks hard expiration timestamps and scopes correctly', function () {
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    // 1. Active non-expired sandbox
    $active = Sandbox::create([
        'incident_id' => $incident->id,
        'sandbox_id' => 'sb_active_01',
        'runtime' => 'node',
        'status' => 'active',
        'expires_at' => now()->addMinutes(10),
    ]);

    // 2. Expired sandbox
    $expired = Sandbox::create([
        'incident_id' => $incident->id,
        'sandbox_id' => 'sb_expired_01',
        'runtime' => 'node',
        'status' => 'active',
        'expires_at' => now()->subMinutes(2),
    ]);

    expect(Sandbox::active()->count())->toBe(2)
        ->and(Sandbox::expired()->count())->toBe(1)
        ->and(Sandbox::expired()->first()->sandbox_id)->toBe('sb_expired_01');
});

test('ReapExpiredSandboxesCommand reaps sandboxes past their hard expiration ceiling', function () {
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    $expiredSandbox = Sandbox::create([
        'incident_id' => $incident->id,
        'sandbox_id' => 'sb_reap_target',
        'runtime' => 'node',
        'status' => 'active',
        'expires_at' => now()->subMinutes(5),
    ]);

    Artisan::call('sandboxes:reap');

    $expiredSandbox->refresh();
    expect($expiredSandbox->status)->toBe('expired')
        ->and($expiredSandbox->destroyed_at)->not->toBeNull();
});

test('ReproductionAgent runs deterministic reproduction fallback and records structured evidence', function () {
    Queue::fake();
    config(['services.anthropic.key' => null]);
    $incident = Incident::factory()->create([
        'repository' => 'acme/webapp',
        'status' => IncidentStatus::REPRODUCING,
    ]);

    $agent = app(ReproductionAgent::class);
    $result = $agent->reproduce($incident);

    expect($result->success)->toBeTrue()
        ->and($result->data['reproduced'])->toBeTrue()
        ->and($result->data['command'])->toBe('npm test');

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::REPRODUCED)
        ->and($incident->metadata['reproduction_result']['reproduced'])->toBeTrue();
});

test('ReproductionAgent executes multi-turn ReAct loop when Anthropic API is active', function () {
    Queue::fake();
    config(['services.anthropic.key' => 'test-api-key']);

    $incident = Incident::factory()->create([
        'repository' => 'acme/webapp',
        'status' => IncidentStatus::REPRODUCING,
    ]);

    // Mock multi-turn Claude ReAct responses
    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::sequence()
            // Turn 1: Claude calls sandbox.create_environment
            ->push([
                'id' => 'msg_01',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'call_01',
                        'name' => 'sandbox.create_environment',
                        'input' => ['incident_id' => $incident->incident_number, 'ecosystem' => 'node'],
                    ],
                ],
                'stop_reason' => 'tool_use',
            ], 200)
            // Turn 2: Claude calls record_reproduction_result
            ->push([
                'id' => 'msg_02',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'call_02',
                        'name' => 'record_reproduction_result',
                        'input' => [
                            'reproduced' => true,
                            'command' => 'npm run test:security',
                            'exit_code' => 0,
                            'stdout' => 'EXPLOIT SUCCESS: Session hijacked',
                            'stderr' => '',
                            'duration_ms' => 3500.0,
                            'environment' => ['runtime' => 'node', 'version' => '22'],
                            'artifacts' => [['type' => 'poc_script', 'path' => 'test/exploit.js']],
                            'observations' => ['Confirmed prototype pollution in src/merge.js:12'],
                        ],
                    ],
                ],
                'stop_reason' => 'tool_use',
            ], 200),
    ]);

    $agent = app(ReproductionAgent::class);
    $dto = $agent->execute($incident);

    expect($dto)->toBeInstanceOf(ReproductionResultDTO::class)
        ->and($dto->isReproduced())->toBeTrue()
        ->and($dto->command)->toBe('npm run test:security')
        ->and($dto->getPoCScript())->toBe('test/exploit.js');

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::REPRODUCED);
});

test('ReproductionWorkflowEngine coordinates execution and enforces 10-minute sandbox TTL', function () {
    Queue::fake();
    config(['services.anthropic.key' => null]);
    $incident = Incident::factory()->create([
        'repository' => 'acme/webapp',
        'status' => IncidentStatus::REPRODUCING,
    ]);

    $engine = app(ReproductionWorkflowEngine::class);
    $dto = $engine->run($incident);

    expect($dto->isReproduced())->toBeTrue();

    // Verify sandbox TTL was created with expires_at ~ now + 10 mins
    $sandbox = Sandbox::where('incident_id', $incident->id)->first();
    expect($sandbox)->not->toBeNull()
        ->and($sandbox->expires_at)->toBeGreaterThan(now()->addMinutes(8));
});
