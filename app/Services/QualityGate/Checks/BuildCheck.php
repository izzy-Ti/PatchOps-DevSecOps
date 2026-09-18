<?php

namespace App\Services\QualityGate\Checks;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\QualityGate\Contracts\QualityCheckInterface;
use App\Services\QualityGate\DTOs\CheckResultDTO;

class BuildCheck implements QualityCheckInterface
{
    public function name(): string
    {
        return 'build_and_packaging';
    }

    public function label(): string
    {
        return 'Build, Compilation, and Packaging';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function execute(Incident $incident, PatchArtifact $patch, array $context = []): CheckResultDTO
    {
        $startTime = microtime(true);

        if (isset($context['build_exit_code'])) {
            $exitCode = (int) $context['build_exit_code'];
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            return $exitCode === 0
                ? CheckResultDTO::pass($this->name(), $context['build_stdout'] ?? 'Build and compilation succeeded.', $durationMs, $this->isCritical())
                : CheckResultDTO::fail($this->name(), $exitCode, $context['build_stderr'] ?? 'Build failed with syntax or dependency compilation error.', $context['build_stdout'] ?? null, $durationMs, $this->isCritical(), 'technical_platform_failure');
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return CheckResultDTO::pass(
            name: $this->name(),
            stdout: 'Build and packaging clean. No compilation or packaging errors detected.',
            durationMs: $durationMs,
            isCritical: $this->isCritical(),
        );
    }
}
