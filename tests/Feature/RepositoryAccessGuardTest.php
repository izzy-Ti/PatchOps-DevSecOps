<?php

use App\Exceptions\MCP\RepositoryAccessDeniedException;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\MCP\Guards\RepositoryAccessGuard;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Incident model provides normalized getRepository helper', function () {
    $incident = Incident::factory()->create(['repository' => '  Laravel/FrameWork  ']);

    expect($incident->getRepository())->toBe('laravel/framework');
});

test('RepositoryAccessGuard permits matching repository and ignores casing/whitespace', function () {
    $guard = app(RepositoryAccessGuard::class);
    $incident = Incident::factory()->create(['repository' => 'acme/payment-gateway']);

    $guard->validate(
        incident: $incident,
        arguments: ['repository' => 'ACME/PAYMENT-GATEWAY', 'path' => 'src/Charge.php'],
        toolName: 'github.get_file',
    );

    expect(true)->toBeTrue();
});

test('RepositoryAccessGuard rejects cross-repository access with RepositoryAccessDeniedException and logs security audit', function () {
    $guard = app(RepositoryAccessGuard::class);
    $incident = Incident::factory()->create(['repository' => 'acme/payment-gateway']);

    try {
        $guard->validate(
            incident: $incident,
            arguments: ['repository' => 'target/unauthorized-vault', 'path' => 'keys.json'],
            toolName: 'github.get_file',
        );
        $this->fail('Expected RepositoryAccessDeniedException was not thrown.');
    } catch (RepositoryAccessDeniedException $e) {
        expect($e->requestedRepo)->toBe('target/unauthorized-vault')
            ->and($e->authorizedRepo)->toBe('acme/payment-gateway')
            ->and($e->toolName)->toBe('github.get_file');
    }

    // Security audit event logged
    expect(AuditLog::where('event', 'security.cross_repository_access_denied')->count())->toBe(1);
    $log = AuditLog::where('event', 'security.cross_repository_access_denied')->first();
    expect($log->payload['requested_repo'])->toBe('target/unauthorized-vault')
        ->and($log->payload['authorized_repo'])->toBe('acme/payment-gateway');
});

test('MCPToolGateway halts cross-repository execution before running any tool code', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/payment-gateway']);

    expect(fn () => $gateway->execute(
        role: AgentRole::TRIAGE,
        toolName: 'github.get_repository',
        arguments: ['repository' => 'attacker/malicious-repo'],
        context: $incident,
    ))->toThrow(RepositoryAccessDeniedException::class);
});
