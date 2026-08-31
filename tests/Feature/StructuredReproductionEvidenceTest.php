<?php

use App\DTOs\ReproductionResultDTO;
use App\Enums\IncidentStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('ReproductionResultDTO handles data serialization and helper methods correctly', function () {
    $dto = ReproductionResultDTO::fromArray([
        'reproduced' => true,
        'command' => 'node test/poc_cve_2026_12345.js',
        'exit_code' => 0,
        'stdout' => 'VULNERABILITY DETECTED: Admin session created via prototype pollution',
        'stderr' => '',
        'duration_ms' => 3840.5,
        'environment' => [
            'runtime' => 'node',
            'version' => '22.4.1',
            'package_manager' => 'npm',
        ],
        'artifacts' => [
            [
                'type' => 'poc_script',
                'path' => 'test/poc_cve_2026_12345.js',
            ],
            [
                'call_site' => 'src/middleware/auth.ts:42',
            ],
        ],
        'observations' => [
            'Payload triggered prototype pollution in src/utils/merge.ts:15',
            'Application returned unauthorized admin data on /api/v1/auth',
        ],
    ]);

    expect($dto->isReproduced())->toBeTrue()
        ->and($dto->exitCode)->toBe(0)
        ->and($dto->durationMs)->toBe(3840.5)
        ->and($dto->getPoCScript())->toBe('test/poc_cve_2026_12345.js');

    $callSites = $dto->getVulnerableCallSites();
    expect($callSites)->toContain('src/utils/merge.ts:15')
        ->and($callSites)->toContain('src/middleware/auth.ts:42');

    $array = $dto->toArray();
    expect($array['reproduced'])->toBeTrue()
        ->and($array['command'])->toBe('node test/poc_cve_2026_12345.js');
});

test('MCPToolGateway executes record_reproduction_result terminal tool and updates incident state', function () {
    Queue::fake();
    $gateway = app(MCPToolGateway::class);
    $incident = Incident::factory()->create([
        'repository' => 'acme/webapp',
        'status' => IncidentStatus::REPRODUCING,
    ]);

    $result = $gateway->invoke(
        role: AgentRole::REPRODUCTION,
        toolName: 'record_reproduction_result',
        arguments: [
            'reproduced' => true,
            'command' => 'npm run test:security',
            'exit_code' => 0,
            'stdout' => 'EXPLOIT SUCCESS: Unauthorized token minted',
            'stderr' => '',
            'duration_ms' => 4120.0,
            'environment' => [
                'runtime' => 'node',
                'version' => '20',
            ],
            'artifacts' => [
                ['type' => 'poc_script', 'path' => 'tests/security/exploit.test.js'],
            ],
            'observations' => [
                'Exploit confirmed vulnerability in src/jwt.ts:88',
            ],
        ],
        context: $incident,
    );

    expect($result['success'])->toBeTrue()
        ->and($result['data']['reproduced'])->toBeTrue()
        ->and($result['data']['poc_script'])->toBe('tests/security/exploit.test.js');

    // 1. Relational incident_evidences record verified
    $evidence = IncidentEvidence::where('incident_id', $incident->id)->first();
    expect($evidence)->not->toBeNull()
        ->and($evidence->stage)->toBe('reproduction')
        ->and($evidence->reproduced)->toBeTrue()
        ->and($evidence->command)->toBe('npm run test:security')
        ->and($evidence->observations)->toContain('Exploit confirmed vulnerability in src/jwt.ts:88');

    // 2. Incident state transition verified
    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::REPRODUCED)
        ->and($incident->metadata['reproduction_result']['reproduced'])->toBeTrue();

    // 3. Audit log entry recorded
    $log = AuditLog::where('event', 'reproduction.evidence_recorded')->first();
    expect($log)->not->toBeNull()
        ->and($log->payload['reproduced'])->toBeTrue()
        ->and($log->payload['command'])->toBe('npm run test:security');
});
