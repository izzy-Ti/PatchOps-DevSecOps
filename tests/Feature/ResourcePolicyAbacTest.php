<?php

use App\Exceptions\MCP\ResourceAccessDeniedException;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use App\Tools\Permissions\ResourcePolicy;
use App\Tools\Permissions\ToolScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ResourcePolicy enforces strict repository scope boundary', function () {
    $policy = app(ResourcePolicy::class);
    $incident = Incident::factory()->create(['repository' => 'acme/billing-api']);

    // 1. Authorized matching repository
    $policy->validate(
        scope: ToolScope::GITHUB_READ,
        arguments: ['repository' => 'acme/billing-api', 'path' => 'src/Auth.php'],
        incident: $incident,
    );
    expect(true)->toBeTrue();

    // 2. Cross-repository access attempt -> Denied
    expect(fn () => $policy->validate(
        scope: ToolScope::GITHUB_READ,
        arguments: ['repository' => 'victim/secret-repo', 'path' => 'src/Auth.php'],
        incident: $incident,
    ))->toThrow(ResourceAccessDeniedException::class);
});

test('ResourcePolicy blocks directory traversal and sensitive secret files', function () {
    $policy = app(ResourcePolicy::class);
    $incident = Incident::factory()->create(['repository' => 'acme/billing-api']);

    // 1. Directory traversal
    expect(fn () => $policy->validate(
        scope: ToolScope::GITHUB_READ,
        arguments: ['repository' => 'acme/billing-api', 'path' => '../../etc/passwd'],
        incident: $incident,
    ))->toThrow(ResourceAccessDeniedException::class);

    // 2. Sensitive .env file
    expect(fn () => $policy->validate(
        scope: ToolScope::GITHUB_READ,
        arguments: ['repository' => 'acme/billing-api', 'path' => '.env'],
        incident: $incident,
    ))->toThrow(ResourceAccessDeniedException::class);

    // 3. Sensitive ssh private key
    expect(fn () => $policy->validate(
        scope: ToolScope::GITHUB_READ,
        arguments: ['repository' => 'acme/billing-api', 'path' => 'config/id_rsa'],
        incident: $incident,
    ))->toThrow(ResourceAccessDeniedException::class);

    // 4. Git config
    expect(fn () => $policy->validate(
        scope: ToolScope::GITHUB_READ,
        arguments: ['repository' => 'acme/billing-api', 'path' => '.git/config'],
        incident: $incident,
    ))->toThrow(ResourceAccessDeniedException::class);
});

test('ResourcePolicy enforces sandbox workspace isolation boundaries', function () {
    $policy = app(ResourcePolicy::class);
    $incident = Incident::factory()->create([
        'incident_number' => 'INC-ABAC-001',
    ]);

    // 1. Allowed workspace belonging to incident
    $policy->validate(
        scope: ToolScope::SANDBOX_EXECUTE,
        arguments: ['workspace_id' => "sbx-{$incident->id}", 'command' => 'npm test'],
        incident: $incident,
    );
    expect(true)->toBeTrue();

    // 2. Foreign sandbox workspace -> Denied
    expect(fn () => $policy->validate(
        scope: ToolScope::SANDBOX_EXECUTE,
        arguments: ['workspace_id' => 'sbx-foreign-workspace-99', 'command' => 'npm test'],
        incident: $incident,
    ))->toThrow(ResourceAccessDeniedException::class);
});

test('MCPToolGateway logs security audit trail on ABAC resource denial', function () {
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create(['repository' => 'acme/billing-api']);

    try {
        $gateway->execute(
            role: AgentRole::TRIAGE,
            toolName: 'github.get_file',
            arguments: [
                'repository' => 'victim/other-service',
                'path' => 'composer.json',
            ],
            context: $incident,
        );
        $this->fail('Expected ResourceAccessDeniedException was not thrown.');
    } catch (ResourceAccessDeniedException $e) {
        expect($e->violatingResource)->toBe('victim/other-service');
    }

    // Security audit event logged
    expect(AuditLog::where('event', 'security.resource_access_denied')->count())->toBe(1);
    $log = AuditLog::where('event', 'security.resource_access_denied')->first();
    expect($log->payload['violating_resource'])->toBe('victim/other-service')
        ->and($log->payload['tool'])->toBe('github.get_file');
});
