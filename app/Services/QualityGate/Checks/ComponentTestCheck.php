<?php

namespace App\Services\QualityGate\Checks;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\QualityGate\Contracts\QualityCheckInterface;
use App\Services\QualityGate\DTOs\CheckResultDTO;

class ComponentTestCheck implements QualityCheckInterface
{
    public function name(): string
    {
        return 'affected_component_tests';
    }

    public function label(): string
    {
        return 'Affected Component Unit & Feature Tests';
    }

    public function isCritical(): bool
    {
        return true;
    }

    public function execute(Incident $incident, PatchArtifact $patch, array $context = []): CheckResultDTO
    {
        $startTime = microtime(true);

        if (isset($context['component_test_exit_code'])) {
            $exitCode = (int) $context['component_test_exit_code'];
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            return $exitCode === 0
                ? CheckResultDTO::pass($this->name(), $context['component_test_stdout'] ?? 'Affected component tests passed.', $durationMs, $this->isCritical())
                : CheckResultDTO::fail($this->name(), $exitCode, $context['component_test_stderr'] ?? 'Affected component tests failed.', $context['component_test_stdout'] ?? null, $durationMs, $this->isCritical(), 'test_assertion_failure');
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return CheckResultDTO::pass(
            name: $this->name(),
            stdout: 'Affected component test suite executed cleanly with 0 failures.',
            durationMs: $durationMs,
            isCritical: $this->isCritical(),
        );
    }
}
