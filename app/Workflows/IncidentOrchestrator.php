<?php

namespace App\Workflows;

use App\DTOs\PatchResultDTO;
use App\DTOs\ReproductionResultDTO;
use App\DTOs\TriageResultDTO;
use App\DTOs\ValidationResultDTO;
use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\GeneratePatchJob;
use App\Jobs\HandleIncidentFailureJob;
use App\Jobs\ReproduceIncidentJob;
use App\Jobs\TriageIncidentJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;
use App\Services\Incident\IncidentStateMachine;

class IncidentOrchestrator
{
    /**
     * Maximum allowed automated patch synthesis retry attempts before escalation.
     */
    public const MAX_PATCH_ITERATIONS = 3;

    /**
     * Create a new orchestrator instance.
     */
    public function __construct(
        protected ?IncidentStateMachine $stateMachine = null,
    ) {
        $this->stateMachine ??= app(IncidentStateMachine::class);
    }

    /**
     * Inspect incident status and dispatch corresponding asynchronous workflow job.
     */
    public function handle(Incident $incident): void
    {
        $status = $incident->status instanceof IncidentStatus
            ? $incident->status
            : (IncidentStatus::tryFrom((string) $incident->status) ?? IncidentStatus::RECEIVED);

        match ($status) {
            IncidentStatus::RECEIVED => TriageIncidentJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::PRIORITIZED => ReproduceIncidentJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::REPRODUCED => GeneratePatchJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::VALIDATING => ValidatePatchJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::VERIFIED => CreatePullRequestJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::FAILED,
            IncidentStatus::ESCALATED => HandleIncidentFailureJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::AWAITING_APPROVAL,
            IncidentStatus::TRIAGING,
            IncidentStatus::REPRODUCING,
            IncidentStatus::PATCHING,
            IncidentStatus::PR_CREATED,
            IncidentStatus::CI_RUNNING,
            IncidentStatus::RESOLVED,
            IncidentStatus::CLOSED => null,
        };
    }

    /**
     * Handle the structured result from TriageAgent.
     */
    public function handleTriageResult(Incident $incident, TriageResultDTO $result): void
    {
        if ($result->isValid()) {
            $severityEnum = VulnerabilitySeverity::tryFrom(strtolower((string) $result->severity)) ?? VulnerabilitySeverity::MEDIUM;

            $priorityValue = strtolower((string) $result->priority);
            $priorityEnum = match ($priorityValue) {
                'critical', 'urgent' => IncidentPriority::URGENT,
                'high' => IncidentPriority::HIGH,
                'low' => IncidentPriority::LOW,
                default => IncidentPriority::MEDIUM,
            };

            $incident->severity = $severityEnum;
            $incident->priority = $priorityEnum;
            $incident->metadata = array_merge($incident->metadata ?? [], [
                'production_exposed' => $result->productionExposed,
                'affected_component' => $result->affectedComponent,
                'triage_reason' => $result->reason,
                'triaged_at' => now()->toIso8601String(),
            ]);
            $incident->save();

            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::PRIORITIZED,
                reason: $result->reason,
                actorType: 'agent',
                actorId: 'triage-agent',
                metadata: [
                    'severity' => $result->severity,
                    'priority' => $result->priority,
                    'production_exposed' => $result->productionExposed,
                    'affected_component' => $result->affectedComponent,
                ],
            );

            ReproduceIncidentJob::dispatch($incident)->onQueue('incidents');
        } else {
            $failureReason = 'Triage failed: '.($result->errorMessage ?? 'Missing required fields or invalid triage structure.');

            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::ESCALATED,
                reason: $failureReason,
                actorType: 'agent',
                actorId: 'triage-agent',
                metadata: [
                    'error' => $result->errorMessage,
                ],
            );
        }
    }

    /**
     * Handle the structured result from ReproductionAgent.
     */
    public function handleReproductionResult(Incident $incident, ReproductionResultDTO $result): void
    {
        if ($result->reproduced) {
            $incident->metadata = array_merge($incident->metadata ?? [], [
                'poc_script' => $result->pocScript,
                'reproduction_stdout' => $result->stdout,
                'reproduction_stderr' => $result->stderr,
                'reproduction_summary' => $result->summary,
                'reproduction_time_seconds' => $result->executionTimeSeconds,
                'reproduced_at' => now()->toIso8601String(),
            ]);
            $incident->save();

            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::REPRODUCED,
                reason: $result->summary ?? 'Vulnerability successfully reproduced in isolated sandbox.',
                actorType: 'agent',
                actorId: 'reproduction-agent',
                metadata: [
                    'artifacts' => $result->artifacts,
                    'time_seconds' => $result->executionTimeSeconds,
                ],
            );

            GeneratePatchJob::dispatch($incident)->onQueue('incidents');
        } elseif ($result->sandboxSuccess) {
            $incident->metadata = array_merge($incident->metadata ?? [], [
                'reproduction_failure_reason' => $result->failureReason,
                'reproduction_stdout' => $result->stdout,
                'reproduction_stderr' => $result->stderr,
            ]);
            $incident->save();

            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::FAILED,
                reason: 'Reproduction failed: '.($result->failureReason ?? 'Vulnerability behavior not observed in sandbox.'),
                actorType: 'agent',
                actorId: 'reproduction-agent',
                metadata: [
                    'failure_reason' => $result->failureReason,
                    'exit_code' => $result->exitCode,
                ],
            );
        } else {
            $incident->metadata = array_merge($incident->metadata ?? [], [
                'reproduction_error' => $result->failureReason,
            ]);
            $incident->save();

            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::ESCALATED,
                reason: 'Reproduction sandbox error: '.($result->failureReason ?? 'Sandbox environment failed to initialize or execute.'),
                actorType: 'agent',
                actorId: 'reproduction-agent',
                metadata: [
                    'error' => $result->failureReason,
                ],
            );
        }
    }

    /**
     * Handle the structured result from PatchAgent.
     */
    public function handlePatchResult(Incident $incident, PatchResultDTO $result): void
    {
        if ($result->isValid()) {
            $incident->root_cause = $result->rootCause;
            $incident->metadata = array_merge($incident->metadata ?? [], [
                'fix_summary' => $result->fixSummary,
                'diff' => $result->diff,
                'changed_files' => $result->changedFiles,
                'tests_added' => $result->testsAdded,
                'patched_at' => now()->toIso8601String(),
            ]);
            $incident->save();

            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::VALIDATING,
                reason: $result->fixSummary ?? 'Security patch and regression tests synthesized.',
                actorType: 'agent',
                actorId: 'patch-agent',
                metadata: [
                    'changed_files' => $result->changedFiles,
                    'tests_added' => $result->testsAdded,
                ],
            );

            ValidatePatchJob::dispatch($incident)->onQueue('incidents');
        } else {
            $incident->metadata = array_merge($incident->metadata ?? [], [
                'patch_failure_reason' => $result->failureReason,
            ]);
            $incident->save();

            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::ESCALATED,
                reason: 'Patch synthesis failed: '.($result->failureReason ?? 'Invalid patch schema or empty diff output.'),
                actorType: 'agent',
                actorId: 'patch-agent',
                metadata: [
                    'error' => $result->failureReason,
                ],
            );
        }
    }

    /**
     * Handle the structured result from ValidationAgent with repair feedback loop & max attempts boundary.
     */
    public function handleValidationResult(Incident $incident, ValidationResultDTO $result): void
    {
        if ($result->passed) {
            $attempts = $incident->getPatchAttempts();
            $incident->metadata = array_merge($incident->metadata ?? [], [
                'validation_test_output' => $result->testOutput,
                'validation_build_output' => $result->buildOutput,
                'validation_summary' => $result->summary,
                'validated_at' => now()->toIso8601String(),
            ]);
            $incident->save();

            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::AWAITING_APPROVAL,
                reason: "Patch successfully verified on attempt {$attempts}",
                actorType: 'agent',
                actorId: 'validation-agent',
                metadata: [
                    'summary' => $result->summary,
                    'attempts' => $attempts,
                ],
            );
        } else {
            $currentAttempt = $incident->incrementPatchAttempts();

            $historyItem = [
                'attempt' => $currentAttempt,
                'diff' => $incident->metadata['diff'] ?? null,
                'feedback' => $result->feedback,
                'test_output' => $result->testOutput,
                'build_output' => $result->buildOutput,
                'failed_at' => now()->toIso8601String(),
            ];
            $history = $incident->metadata['validation_history'] ?? [];
            $history[] = $historyItem;

            $incident->metadata = array_merge($incident->metadata ?? [], [
                'last_validation_feedback' => $result->feedback,
                'validation_test_output' => $result->testOutput,
                'validation_build_output' => $result->buildOutput,
                'validation_history' => $history,
            ]);
            $incident->save();

            if ($currentAttempt < self::MAX_PATCH_ITERATIONS) {
                $this->stateMachine->transition(
                    incident: $incident,
                    targetStatus: IncidentStatus::PATCHING,
                    reason: "Validation failed (Attempt {$currentAttempt}/".self::MAX_PATCH_ITERATIONS.'). Retrying patch synthesis.',
                    actorType: 'agent',
                    actorId: 'validation-agent',
                    metadata: [
                        'feedback' => $result->feedback,
                        'attempt' => $currentAttempt,
                    ],
                );

                GeneratePatchJob::dispatch($incident)->onQueue('incidents');
            } else {
                $this->stateMachine->transition(
                    incident: $incident,
                    targetStatus: IncidentStatus::ESCALATED,
                    reason: 'Exhausted maximum patch attempts ('.self::MAX_PATCH_ITERATIONS.'). Manual intervention required.',
                    actorType: 'agent',
                    actorId: 'validation-agent',
                    metadata: [
                        'feedback' => $result->feedback,
                        'attempts' => $currentAttempt,
                    ],
                );
            }
        }
    }
}
