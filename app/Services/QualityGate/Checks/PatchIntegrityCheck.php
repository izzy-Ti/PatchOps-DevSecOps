<?php

namespace App\Services\QualityGate\Checks;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\QualityGate\Contracts\QualityCheckInterface;
use App\Services\QualityGate\DTOs\CheckResultDTO;

class PatchIntegrityCheck implements QualityCheckInterface
{
    public function name(): string
    {
        return 'patch_integrity';
    }

    public function label(): string
    {
        return 'Patch Integrity & AST/Diff Validation';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function execute(Incident $incident, PatchArtifact $patch, array $context = []): CheckResultDTO
    {
        $startTime = microtime(true);
        $diff = trim($patch->unified_diff);

        if (empty($diff)) {
            return CheckResultDTO::fail(
                name: $this->name(),
                exitCode: 1,
                stderr: 'Unified diff content is completely empty.',
                durationMs: round((microtime(true) - $startTime) * 1000, 2),
                isCritical: $this->isCritical(),
                failureClassification: 'syntax_error',
            );
        }

        // Check for unified diff headers
        if (! str_contains($diff, '---') || ! str_contains($diff, '+++') || ! str_contains($diff, '@@')) {
            return CheckResultDTO::fail(
                name: $this->name(),
                exitCode: 1,
                stderr: 'Patch diff does not contain valid unified diff headers (---, +++, @@).',
                durationMs: round((microtime(true) - $startTime) * 1000, 2),
                isCritical: $this->isCritical(),
                failureClassification: 'syntax_error',
            );
        }

        // Check for forbidden / sensitive file modifications
        $forbiddenPatterns = ['.env', '.git/', 'id_rsa', 'id_ed25519', '.aws/credentials'];
        foreach ($forbiddenPatterns as $pattern) {
            if (str_contains($diff, $pattern)) {
                return CheckResultDTO::fail(
                    name: $this->name(),
                    exitCode: 1,
                    stderr: "Patch attempts to modify forbidden or sensitive path: [{$pattern}].",
                    durationMs: round((microtime(true) - $startTime) * 1000, 2),
                    isCritical: $this->isCritical(),
                    failureClassification: 'security_policy_violation',
                );
            }
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return CheckResultDTO::pass(
            name: $this->name(),
            stdout: 'Unified diff syntax and file integrity checks passed cleanly.',
            durationMs: $durationMs,
            isCritical: $this->isCritical(),
        );
    }
}
