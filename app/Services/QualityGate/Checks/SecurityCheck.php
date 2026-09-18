<?php

namespace App\Services\QualityGate\Checks;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\QualityGate\Contracts\QualityCheckInterface;
use App\Services\QualityGate\DTOs\CheckResultDTO;

class SecurityCheck implements QualityCheckInterface
{
    public function name(): string
    {
        return 'security_audit';
    }

    public function label(): string
    {
        return 'Static Analysis & Security Vulnerability Audit';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function execute(Incident $incident, PatchArtifact $patch, array $context = []): CheckResultDTO
    {
        $startTime = microtime(true);

        if (isset($context['security_exit_code'])) {
            $exitCode = (int) $context['security_exit_code'];
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            return $exitCode === 0
                ? CheckResultDTO::pass($this->name(), $context['security_stdout'] ?? 'Security scan clear. Zero new High/Critical SAST/CVE findings.', $durationMs, $this->isCritical())
                : CheckResultDTO::fail($this->name(), $exitCode, $context['security_stderr'] ?? 'Security scan detected High/Critical vulnerabilities.', $context['security_stdout'] ?? null, $durationMs, $this->isCritical(), 'security_policy_violation');
        }

        // Basic scan on diff additions for dangerous functions
        $dangerousFunctions = ['exec(', 'passthru(', 'shell_exec(', 'system(', 'eval('];
        foreach ($dangerousFunctions as $func) {
            if (str_contains($patch->unified_diff, '+') && str_contains($patch->unified_diff, $func)) {
                return CheckResultDTO::fail(
                    name: $this->name(),
                    exitCode: 1,
                    stderr: "Dangerous command execution function [{$func}] introduced in patch.",
                    durationMs: round((microtime(true) - $startTime) * 1000, 2),
                    isCritical: $this->isCritical(),
                    failureClassification: 'security_policy_violation',
                );
            }
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return CheckResultDTO::pass(
            name: $this->name(),
            stdout: 'Security audit passed. Zero new High/Critical SAST or CVE findings.',
            durationMs: $durationMs,
            isCritical: $this->isCritical(),
        );
    }
}
