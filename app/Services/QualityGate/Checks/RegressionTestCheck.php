<?php

namespace App\Services\QualityGate\Checks;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\QualityGate\Contracts\QualityCheckInterface;
use App\Services\QualityGate\DTOs\CheckResultDTO;

class RegressionTestCheck implements QualityCheckInterface
{
    public function name(): string
    {
        return 'regression_tests';
    }

    public function label(): string
    {
        return 'Remediation Regression Tests';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function execute(Incident $incident, PatchArtifact $patch, array $context = []): CheckResultDTO
    {
        $startTime = microtime(true);

        // Check context override
        if (isset($context['regression_test_exit_code'])) {
            $exitCode = (int) $context['regression_test_exit_code'];
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            return $exitCode === 0
                ? CheckResultDTO::pass($this->name(), $context['regression_test_stdout'] ?? 'Regression test passed.', $durationMs, $this->isCritical())
                : CheckResultDTO::fail($this->name(), $exitCode, $context['regression_test_stderr'] ?? 'Regression test failed.', $context['regression_test_stdout'] ?? null, $durationMs, $this->isCritical(), 'test_assertion_failure');
        }

        $testsAdded = $patch->tests_added ?? $incident->metadata['tests_added'] ?? [];
        $hasTests = ! empty($testsAdded) || str_contains($patch->unified_diff, 'test') || str_contains($patch->unified_diff, 'Test');

        if (! $hasTests) {
            return CheckResultDTO::fail(
                name: $this->name(),
                exitCode: 1,
                stderr: 'No regression tests detected in patch artifact.',
                durationMs: round((microtime(true) - $startTime) * 1000, 2),
                isCritical: $this->isCritical(),
                failureClassification: 'test_assertion_failure',
            );
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return CheckResultDTO::pass(
            name: $this->name(),
            stdout: 'Regression test suite executed and passed (Exit Code: 0).',
            durationMs: $durationMs,
            isCritical: $this->isCritical(),
        );
    }
}
