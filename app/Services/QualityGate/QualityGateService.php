<?php

namespace App\Services\QualityGate;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Models\QualityGateCheck;
use App\Models\QualityGateRun;
use App\Services\QualityGate\Checks\BuildCheck;
use App\Services\QualityGate\Checks\ComponentTestCheck;
use App\Services\QualityGate\Checks\DependencyCheck;
use App\Services\QualityGate\Checks\FullTestSuiteCheck;
use App\Services\QualityGate\Checks\LintCheck;
use App\Services\QualityGate\Checks\PatchIntegrityCheck;
use App\Services\QualityGate\Checks\RegressionTestCheck;
use App\Services\QualityGate\Checks\SecurityCheck;
use App\Services\QualityGate\Checks\VulnerabilityCheck;
use App\Services\QualityGate\Contracts\QualityCheckInterface;
use App\Services\QualityGate\DTOs\CheckResultDTO;
use App\Services\QualityGate\DTOs\QualityGateResultDTO;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QualityGateService
{
    /**
     * @var array<int, class-string<QualityCheckInterface>>
     */
    protected array $defaultCheckClasses = [
        PatchIntegrityCheck::class,
        DependencyCheck::class,
        LintCheck::class,
        RegressionTestCheck::class,
        ComponentTestCheck::class,
        FullTestSuiteCheck::class,
        BuildCheck::class,
        SecurityCheck::class,
        VulnerabilityCheck::class,
    ];

    /**
     * @param  array<int, QualityCheckInterface>|null  $customChecks
     */
    public function __construct(
        protected ?array $customChecks = null
    ) {}

    /**
     * Execute the deterministic Quality Gate verification suite against a candidate patch.
     *
     * @param  array<string, mixed>  $context
     */
    public function evaluate(Incident $incident, PatchArtifact $patch, array $context = []): QualityGateResultDTO
    {
        $startTime = microtime(true);
        $checks = $this->resolveChecks();
        $iteration = (int) ($incident->patch_iterations ?? 1);

        /** @var array<int, CheckResultDTO> $results */
        $results = [];
        $failedCheck = null;
        $allPassed = true;

        foreach ($checks as $check) {
            $result = $check->execute($incident, $patch, $context);
            $results[] = $result;

            if (! $result->passed()) {
                $allPassed = false;
                $failedCheck = $result;

                Log::warning("QualityGate: Check [{$check->name()}] failed for incident [{$incident->incident_number}].", [
                    'check' => $check->name(),
                    'exit_code' => $result->exitCode,
                    'is_critical' => $check->isCritical(),
                    'stderr' => $result->stderr,
                ]);

                // Fast-fail rule on critical check failure
                if ($check->isCritical()) {
                    break;
                }
            }
        }

        $totalDurationMs = round((microtime(true) - $startTime) * 1000, 2);

        $failureEvidence = [];
        if (! $allPassed && $failedCheck) {
            $failureEvidence = [
                'failed_check' => $failedCheck->name,
                'status' => $failedCheck->status,
                'exit_code' => $failedCheck->exitCode,
                'stdout' => $failedCheck->stdout,
                'stderr' => $failedCheck->stderr,
                'failure_classification' => $failedCheck->failureClassification,
                'iteration' => $iteration,
            ];
        }

        // Persist run and checks in database
        DB::transaction(function () use ($incident, $patch, $iteration, $allPassed, $results, $totalDurationMs) {
            $passedCount = count(array_filter($results, fn (CheckResultDTO $c) => $c->passed()));
            $failedCount = count(array_filter($results, fn (CheckResultDTO $c) => ! $c->passed()));

            $run = QualityGateRun::create([
                'incident_id' => $incident->id,
                'patch_id' => $patch->id,
                'iteration' => $iteration,
                'passed' => $allPassed,
                'total_checks' => count($results),
                'passed_checks' => $passedCount,
                'failed_checks' => $failedCount,
                'duration_ms' => $totalDurationMs,
            ]);

            foreach ($results as $checkResult) {
                QualityGateCheck::create(array_merge(
                    $checkResult->toModelAttributes(),
                    ['quality_gate_run_id' => $run->id]
                ));
            }
        });

        return new QualityGateResultDTO(
            passed: $allPassed,
            iteration: $iteration,
            checks: $results,
            durationMs: $totalDurationMs,
            failedCheck: $failedCheck,
            failureEvidence: $failureEvidence,
        );
    }

    /**
     * Resolve instantiated check instances.
     *
     * @return array<int, QualityCheckInterface>
     */
    protected function resolveChecks(): array
    {
        if ($this->customChecks !== null) {
            return $this->customChecks;
        }

        return array_map(fn ($class) => app($class), $this->defaultCheckClasses);
    }
}
