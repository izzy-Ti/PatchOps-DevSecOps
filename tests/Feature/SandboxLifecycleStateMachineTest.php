<?php

use App\Exceptions\MCP\InvalidSandboxIdentifierException;
use App\Exceptions\MCP\InvalidSandboxLifecycleStateException;
use App\Models\Incident;
use App\Models\ToolExecution;
use App\Services\MCP\Guards\SandboxLifecycleGuard;
use App\Services\MCP\MCPToolGateway;
use App\Services\Sandbox\DTOs\SandboxContextDTO;
use App\Tools\Enums\AgentRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('SandboxContextDTO serializes and constructs correctly', function () {
    $dto = SandboxContextDTO::fromArray([
        'sandbox_id' => 'sb_01KABC1234XYZ',
        'incident_id' => 'INC-000001',
        'runtime' => 'node20',
        'state' => 'READY',
        'created_at' => '2026-08-31T15:00:00Z',
    ]);

    expect($dto->sandboxId)->toBe('sb_01KABC1234XYZ')
        ->and($dto->incidentId)->toBe('INC-000001')
        ->and($dto->runtime)->toBe('node20')
        ->and($dto->state)->toBe('READY');

    $array = $dto->toArray();
    expect($array['sandbox_id'])->toBe('sb_01KABC1234XYZ')
        ->and($array['state'])->toBe('READY');
});

test('SandboxLifecycleGuard blocks raw Docker container hashes and invalid IDs', function () {
    $guard = app(SandboxLifecycleGuard::class);
    $incident = Incident::factory()->create();

    // 1. Raw 12-char Docker short hash -> Blocked
    expect(fn () => $guard->validate($incident, ['sandbox_id' => '4f8b91a2b3c4'], 'sandbox.execute_command'))
        ->toThrow(InvalidSandboxIdentifierException::class);

    // 2. Raw 64-char Docker full container ID -> Blocked
    $raw64Hash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    expect(fn () => $guard->validate($incident, ['sandbox_id' => $raw64Hash], 'sandbox.execute_command'))
        ->toThrow(InvalidSandboxIdentifierException::class);

    // 3. Un-prefixed random string -> Blocked
    expect(fn () => $guard->validate($incident, ['sandbox_id' => 'random_unprefixed_id'], 'sandbox.execute_command'))
        ->toThrow(InvalidSandboxIdentifierException::class);

    // 4. Safe opaque prefixed IDs -> Allowed
    expect(fn () => $guard->validate($incident, ['sandbox_id' => 'sb_01KABC1234XYZ'], 'sandbox.execute_command'))
        ->not->toThrow(InvalidSandboxIdentifierException::class);

    expect(fn () => $guard->validate($incident, ['sandbox_id' => 'sbx-INC-001-uuid'], 'sandbox.execute_command'))
        ->not->toThrow(InvalidSandboxIdentifierException::class);
});

test('SandboxLifecycleGuard prevents executing commands on already destroyed sandboxes', function () {
    $guard = app(SandboxLifecycleGuard::class);
    $incident = Incident::factory()->create();
    $sandboxId = 'sb_01KTESTDESTROYED';

    // Record sandbox.destroy_sandbox execution as SUCCESS
    ToolExecution::create([
        'incident_id' => $incident->id,
        'tool_name' => 'sandbox.destroy_sandbox',
        'arguments' => ['sandbox_id' => $sandboxId],
        'status' => 'success',
    ]);

    // Attempting to execute command on the destroyed sandbox -> Blocked
    expect(fn () => $guard->validate($incident, ['sandbox_id' => $sandboxId], 'sandbox.execute_command'))
        ->toThrow(InvalidSandboxLifecycleStateException::class);
});

test('MCPToolGateway enforces opaque sandbox ID resolution in full pipeline', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/webapp']);

    // Attempt tool with raw Docker ID -> Gateway returns structured error
    $result = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.execute_command',
        arguments: [
            'sandbox_id' => '1a2b3c4d5e6f',
            'command' => 'npm test',
        ],
        context: $incident,
    );

    expect($result['success'])->toBeFalse()
        ->and($result['error']['code'])->toBe('INVALID_ARGUMENTS');
});
