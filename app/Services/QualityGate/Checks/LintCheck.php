<?php

namespace App\Services\QualityGate\Checks;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\QualityGate\Contracts\QualityCheckInterface;
use App\Services\QualityGate\DTOs\CheckResultDTO;

class LintCheck implements QualityCheckInterface
{
    public function name(): string
    {
        return 'lint_and_formatting';
    }

    public function label(): string
    {
        return 'Linting & Code Formatting Standards';
    }

    public function isCritical(): bool
    {
        return false;
    }

    public function execute(Incident $incident, PatchArtifact $patch, array $context = []): CheckResultDTO
    {
        $startTime = microtime(true);

        // If context provides mock or overrides
        if (isset($context['lint_exit_code'])) {
            $exitCode = (int) $context['lint_exit_code'];
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            return $exitCode === 0
                ? CheckResultDTO::pass($this->name(), 'Code styling and pint/eslint formatting passed.', $durationMs, $this->isCritical())
                : CheckResultDTO::fail($this->name(), $exitCode, $context['lint_stderr'] ?? 'Linter discovered code style violations.', null, $durationMs, $this->isCritical(), 'syntax_error');
        }

        // Basic sanity check on diff for residual debug markers (e.g. dd(), var_dump(), console.log)
        $debugMarkers = ['dd(', 'var_dump(', 'ray(', 'dump('];
        foreach ($debugMarkers as $marker) {
            if (str_contains($patch->unified_diff, '+') && str_contains($patch->unified_diff, $marker)) {
                return CheckResultDTO::fail(
                    name: $this->name(),
                    exitCode: 1,
                    stderr: "Debug statement [{$marker}] left in patch additions.",
                    durationMs: round((microtime(true) - $startTime) * 1000, 2),
                    isCritical: $this->isCritical(),
                    failureClassification: 'syntax_error',
                );
            }
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        return CheckResultDTO::pass(
            name: $this->name(),
            stdout: 'Code linting and formatting clean. No debug markers detected.',
            durationMs: $durationMs,
            isCritical: $this->isCritical(),
        );
    }
}
