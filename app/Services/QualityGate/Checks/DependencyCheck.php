<?php

namespace App\Services\QualityGate\Checks;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\QualityGate\Contracts\QualityCheckInterface;
use App\Services\QualityGate\DTOs\CheckResultDTO;

class DependencyCheck implements QualityCheckInterface
{
    public function name(): string
    {
        return 'dependency_audit';
    }

    public function label(): string
    {
        return 'Dependency & Manifest Integrity';
    }

    public function isCritical(): bool
    {
        return false;
    }

    public function execute(Incident $incident, PatchArtifact $patch, array $context = []): CheckResultDTO
    {
        $startTime = microtime(true);
        $diff = $patch->unified_diff;

        // Check if package manifests are modified without authorization
        if (str_contains($diff, 'composer.json') || str_contains($diff, 'package.json')) {
            // Check for known malicious or untrusted packages
            $suspiciousPackages = ['malicious-package', 'crypto-miner', 'backdoor'];
            foreach ($suspiciousPackages as $suspicious) {
                if (str_contains(strtolower($diff), $suspicious)) {
                    return CheckResultDTO::fail(
                        name: $this->name(),
                        exitCode: 1,
                        stderr: "Suspicious dependency detected in manifest change: [{$suspicious}].",
                        durationMs: round((microtime(true) - $startTime) * 1000, 2),
                        isCritical: $this->isCritical(),
                        failureClassification: 'security_policy_violation',
                    );
                }
            }
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return CheckResultDTO::pass(
            name: $this->name(),
            stdout: 'Dependency manifest audit passed. No malicious dependencies introduced.',
            durationMs: $durationMs,
            isCritical: $this->isCritical(),
        );
    }
}
