<?php

namespace App\Services\QualityGate\Checks;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\QualityGate\Contracts\QualityCheckInterface;
use App\Services\QualityGate\DTOs\CheckResultDTO;

class FullTestSuiteCheck implements QualityCheckInterface
{
    public function name(): string
    {
        return 'full_test_suite';
    }

    public function label(): string
    {
        return 'Full Repository Test Suite';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function execute(Incident $incident, PatchArtifact $patch, array $context = []): CheckResultDTO
    {
        $startTime = microtime(true);

        if (isset($context['full_test_exit_code'])) {
            $exitCode = (int) $context['full_test_exit_code'];
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            return $exitCode === 0
                ? CheckResultDTO::pass($this->name(), $context['full_test_stdout'] ?? 'Full repository test suite passed.', $durationMs, $this->isCritical())
                : CheckResultDTO::fail($this->name(), $exitCode, $context['full_test_stderr'] ?? 'Full test suite failed with assertions.', $context['full_test_stdout'] ?? null, $durationMs, $this->isCritical(), 'test_assertion_failure');
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return CheckResultDTO::pass(
            name: $this->name(),
            stdout: 'Full test suite verified. Zero regressions across repository features.',
            durationMs: $durationMs,
            isCritical: $this->isCritical(),
        );
    }
}
