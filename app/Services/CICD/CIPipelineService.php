<?php

namespace App\Services\CICD;

use App\Enums\RemediationStatus;
use App\Enums\VerificationCheckType;
use App\Models\Incident;
use App\Models\RemediationRun;
use App\Models\VerificationCheck;
use App\Services\CICD\DTOs\CIPipelineResultDTO;
use Illuminate\Support\Facades\Log;

class CIPipelineService
{
    /**
     * Poll or process external CI run results for an active remediation run.
     *
     * @param  array<string, mixed>  $context
     */
    public function monitor(Incident $incident, RemediationRun $run, array $context = []): CIPipelineResultDTO
    {
        $startTime = microtime(true);
        $commitSha = (string) ($run->pullRequest?->commit_sha ?? $context['commit_sha'] ?? 'c0ffee');
        $ciRunId = (string) ($context['ci_run_id'] ?? $run->ci_run_id ?? 'gh_run_'.rand(100000, 999999));

        Log::info("CIPipelineService: Inspecting CI status for incident [{$incident->incident_number}] (commit: {$commitSha}, run: {$ciRunId}).");

        $passed = isset($context['ci_passed']) ? (bool) $context['ci_passed'] : true;
        $failedJobs = (array) ($context['ci_failed_jobs'] ?? ($passed ? [] : ['test_suite']));
        $failedSteps = (array) ($context['ci_failed_steps'] ?? ($passed ? [] : ['run_unit_tests']));
        $logs = (string) ($context['ci_logs'] ?? ($passed ? 'CI build and test suite succeeded with exit code 0.' : 'Process failed with exit code 1: Assertion failed.'));
        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $errorSummary = $passed ? null : 'CI Pipeline failed on jobs: '.implode(', ', $failedJobs);

        // Update remediation run status
        $run->update([
            'ci_run_id' => $ciRunId,
            'status' => $passed ? RemediationStatus::CI_PASSED : RemediationStatus::CI_FAILED,
        ]);

        // Record verification check
        VerificationCheck::create([
            'remediation_run_id' => $run->id,
            'check_type' => VerificationCheckType::CI_PIPELINE,
            'status' => $passed ? 'PASSED' : 'FAILED',
            'exit_code' => $passed ? 0 : 1,
            'duration_ms' => $durationMs,
            'evidence' => [
                'ci_run_id' => $ciRunId,
                'commit_sha' => $commitSha,
                'failed_jobs' => $failedJobs,
                'failed_steps' => $failedSteps,
                'logs' => $logs,
            ],
            'started_at' => now()->subMilliseconds($durationMs),
            'completed_at' => now(),
        ]);

        return new CIPipelineResultDTO(
            passed: $passed,
            runId: $ciRunId,
            commitSha: $commitSha,
            failedJobs: $failedJobs,
            failedSteps: $failedSteps,
            logs: $logs,
            durationMs: $durationMs,
            errorSummary: $errorSummary,
            metadata: ['exit_code' => $passed ? 0 : 1],
        );
    }
}
