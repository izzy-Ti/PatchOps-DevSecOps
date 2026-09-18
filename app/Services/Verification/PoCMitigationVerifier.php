<?php

namespace App\Services\Verification;

use App\Models\Incident;
use App\Services\MCP\MCPToolGateway;
use App\Tools\Enums\AgentRole;
use Throwable;

class PoCMitigationVerifier
{
    /**
     * Signatures indicating an active exploit outcome.
     */
    private const EXPLOIT_INDICATORS = [
        'exploit successful',
        'unauthorized admin session',
        'vulnerability confirmed',
        'root:x:0:0',
        'remote code executed',
        'privilege escalation achieved',
    ];

    public function __construct(
        protected ?MCPToolGateway $gateway = null,
    ) {
        $this->gateway ??= app(MCPToolGateway::class);
    }

    /**
     * Re-execute the original reproduction PoC in the patched workspace to verify behavior inversion.
     *
     * @return array{mitigated: bool, exit_code: int, stdout: string, stderr: string, evidence_changed: bool}
     */
    public function verifyMitigation(Incident $incident, string $sandboxId, string $pocCommand, ?string $agentRunId = null): array
    {
        try {
            $execResult = $this->gateway->execute(
                role: AgentRole::REVIEWER,
                toolName: 'sandbox.execute',
                arguments: [
                    'sandbox_id' => $sandboxId,
                    'command' => $pocCommand,
                    'timeout' => 300,
                ],
                context: $incident,
                agentRunId: $agentRunId ? (int) $agentRunId : null,
            );
        } catch (Throwable $e) {
            return [
                'mitigated' => false,
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => "PoC verification execution error: {$e->getMessage()}",
                'evidence_changed' => false,
            ];
        }

        $stdout = (string) ($execResult['stdout'] ?? '');
        $stderr = (string) ($execResult['stderr'] ?? '');
        $combinedOutput = "{$stdout} {$stderr}";
        $exitCode = (int) ($execResult['exit_code'] ?? 0);

        $exploitStillActive = false;
        foreach (self::EXPLOIT_INDICATORS as $indicator) {
            if (stripos($combinedOutput, $indicator) !== false) {
                $exploitStillActive = true;
                break;
            }
        }

        return [
            'mitigated' => ! $exploitStillActive,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'evidence_changed' => true,
        ];
    }
}
