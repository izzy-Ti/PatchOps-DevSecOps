<?php

namespace App\Workflows;

use App\DTOs\AgentErrorDTO;
use App\DTOs\AgentResultDTO;
use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Exceptions\IncidentConcurrentModificationException;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\GeneratePatchJob;
use App\Jobs\HandleIncidentFailureJob;
use App\Jobs\ReproduceIncidentJob;
use App\Jobs\TriageIncidentJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;
use App\Services\Incident\IncidentStateMachine;
use Closure;
use Illuminate\Support\Facades\Cache;

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
        $this->withLock($incident, function () use ($incident) {
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
        });
    }

    /**
     * Handle the structured result from TriageAgent.
     */
    public function handleTriageResult(Incident $incident, AgentResultDTO $result): void
    {
        $this->withLock($incident, function () use ($incident, $result) {
            if ($result->success) {
                $data = $result->data;
                $severityEnum = VulnerabilitySeverity::tryFrom(strtolower((string) ($data['severity'] ?? ''))) ?? VulnerabilitySeverity::MEDIUM;

                $priorityValue = strtolower((string) ($data['priority'] ?? ''));
                $priorityEnum = match ($priorityValue) {
                    'critical', 'urgent' => IncidentPriority::URGENT,
                    'high' => IncidentPriority::HIGH,
                    'low' => IncidentPriority::LOW,
                    default => IncidentPriority::MEDIUM,
                };

                $incident->severity = $severityEnum;
                $incident->priority = $priorityEnum;
                $incident->metadata = array_merge($incident->metadata ?? [], [
                    'production_exposed' => (bool) ($data['production_exposed'] ?? false),
                    'affected_component' => (string) ($data['affected_component'] ?? ''),
                    'triage_reason' => (string) ($data['reason'] ?? ''),
                    'triaged_at' => now()->toIso8601String(),
                ]);
                $incident->save();

                $this->stateMachine->transition(
                    incident: $incident,
                    targetStatus: IncidentStatus::PRIORITIZED,
                    reason: (string) ($data['reason'] ?? 'Triage analysis completed.'),
                    actorType: 'agent',
                    actorId: 'triage-agent',
                    metadata: [
                        'severity' => $data['severity'] ?? null,
                        'priority' => $data['priority'] ?? null,
                        'production_exposed' => $data['production_exposed'] ?? null,
                        'affected_component' => $data['affected_component'] ?? null,
                    ],
                );

                ReproduceIncidentJob::dispatch($incident)->onQueue('incidents');
            } else {
                $this->recordError($incident, $result);
                $error = $result->error;
                $failureReason = "Triage failed [{$error?->code}]: ".($error?->message ?? 'Missing required fields or invalid triage structure.');

                $this->stateMachine->transition(
                    incident: $incident,
                    targetStatus: IncidentStatus::ESCALATED,
                    reason: $failureReason,
                    actorType: 'agent',
                    actorId: 'triage-agent',
                    metadata: [
                        'error_code' => $error?->code,
                        'error_message' => $error?->message,
                    ],
                );
            }
        });
    }

    /**
     * Handle the structured result from ReproductionAgent.
     */
    public function handleReproductionResult(Incident $incident, AgentResultDTO $result): void
    {
        $this->withLock($incident, function () use ($incident, $result) {
            if ($result->success) {
                $data = $result->data;
                $incident->metadata = array_merge($incident->metadata ?? [], [
                    'reproduction_result' => $data,
                    'poc_script' => $data['poc_script'] ?? null,
                    'reproduction_stdout' => $data['stdout'] ?? null,
                    'reproduction_stderr' => $data['stderr'] ?? null,
                    'reproduction_summary' => $data['summary'] ?? null,
                    'reproduction_time_seconds' => $data['execution_time_seconds'] ?? 0.0,
                    'reproduced_at' => now()->toIso8601String(),
                ]);
                $incident->save();

                $this->stateMachine->transition(
                    incident: $incident,
                    targetStatus: IncidentStatus::REPRODUCED,
                    reason: (string) ($data['summary'] ?? 'Vulnerability successfully reproduced in isolated sandbox.'),
                    actorType: 'agent',
                    actorId: 'reproduction-agent',
                    metadata: [
                        'artifacts' => $data['artifacts'] ?? [],
                        'time_seconds' => $data['execution_time_seconds'] ?? 0.0,
                    ],
                );

                GeneratePatchJob::dispatch($incident)->onQueue('incidents');
            } else {
                $this->recordError($incident, $result);
                $error = $result->error;

                if ($error?->code === AgentErrorDTO::REPRODUCTION_FAILED) {
                    $this->stateMachine->transition(
                        incident: $incident,
                        targetStatus: IncidentStatus::FAILED,
                        reason: 'Reproduction failed: '.($error?->message ?? 'Vulnerability behavior not observed in sandbox.'),
                        actorType: 'agent',
                        actorId: 'reproduction-agent',
                        metadata: [
                            'error_code' => $error?->code,
                            'details' => $error?->details,
                        ],
                    );
                } else {
                    $this->stateMachine->transition(
                        incident: $incident,
                        targetStatus: IncidentStatus::ESCALATED,
                        reason: "Reproduction error [{$error?->code}]: ".($error?->message ?? 'Sandbox environment failed to initialize or execute.'),
                        actorType: 'agent',
                        actorId: 'reproduction-agent',
                        metadata: [
                            'error_code' => $error?->code,
                            'error_message' => $error?->message,
                        ],
                    );
                }
            }
        });
    }

    /**
     * Handle the structured result from PatchAgent.
     */
    public function handlePatchResult(Incident $incident, AgentResultDTO $result): void
    {
        $this->withLock($incident, function () use ($incident, $result) {
            if ($result->success && ! empty($result->data['diff']) && ! empty($result->data['root_cause'])) {
                $data = $result->data;
                $incident->root_cause = (string) ($data['root_cause'] ?? '');
                $incident->metadata = array_merge($incident->metadata ?? [], [
                    'fix_summary' => $data['fix_summary'] ?? '',
                    'diff' => $data['diff'] ?? '',
                    'changed_files' => $data['changed_files'] ?? [],
                    'tests_added' => $data['tests_added'] ?? [],
                    'patched_at' => now()->toIso8601String(),
                ]);
                $incident->save();

                $this->stateMachine->transition(
                    incident: $incident,
                    targetStatus: IncidentStatus::VALIDATING,
                    reason: (string) ($data['fix_summary'] ?? 'Security patch and regression tests synthesized.'),
                    actorType: 'agent',
                    actorId: 'patch-agent',
                    metadata: [
                        'changed_files' => $data['changed_files'] ?? [],
                        'tests_added' => $data['tests_added'] ?? [],
                    ],
                );

                ValidatePatchJob::dispatch($incident)->onQueue('incidents');
            } else {
                $this->recordError($incident, $result);
                $error = $result->error;
                $code = $error?->code ?? AgentErrorDTO::PATCH_SYNTHESIS_FAILED;
                $message = $error?->message ?? 'Invalid patch schema or empty diff output.';

                $this->stateMachine->transition(
                    incident: $incident,
                    targetStatus: IncidentStatus::ESCALATED,
                    reason: "Patch synthesis failed [{$code}]: {$message}",
                    actorType: 'agent',
                    actorId: 'patch-agent',
                    metadata: [
                        'error_code' => $code,
                        'error_message' => $message,
                    ],
                );
            }
        });
    }

    /**
     * Handle the structured result from ValidationAgent with unified failure & repair loop.
     */
    public function handleValidationResult(Incident $incident, AgentResultDTO $result): void
    {
        $this->withLock($incident, function () use ($incident, $result) {
            if ($result->success) {
                $data = $result->data;
                $attempts = $incident->getPatchAttempts();
                $incident->metadata = array_merge($incident->metadata ?? [], [
                    'validation_test_output' => $data['test_output'] ?? '',
                    'validation_build_output' => $data['build_output'] ?? '',
                    'validation_summary' => $data['summary'] ?? '',
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
                        'summary' => $data['summary'] ?? '',
                        'attempts' => $attempts,
                    ],
                );
            } else {
                $this->recordError($incident, $result);
                $error = $result->error;
                $currentAttempt = $incident->incrementPatchAttempts();

                $historyItem = [
                    'attempt' => $currentAttempt,
                    'diff' => $incident->metadata['diff'] ?? null,
                    'feedback' => $error?->message,
                    'test_output' => $error?->details['test_output'] ?? null,
                    'build_output' => $error?->details['build_output'] ?? null,
                    'failed_at' => now()->toIso8601String(),
                ];
                $history = $incident->metadata['validation_history'] ?? [];
                $history[] = $historyItem;

                $incident->metadata = array_merge($incident->metadata ?? [], [
                    'last_validation_feedback' => $error?->message,
                    'validation_history' => $history,
                ]);
                $incident->save();

                $isRecoverable = in_array($error?->code, [AgentErrorDTO::TEST_FAILED, AgentErrorDTO::BUILD_FAILED], true);

                if ($isRecoverable && $currentAttempt < self::MAX_PATCH_ITERATIONS) {
                    $this->stateMachine->transition(
                        incident: $incident,
                        targetStatus: IncidentStatus::PATCHING,
                        reason: "Validation failed (Attempt {$currentAttempt}/".self::MAX_PATCH_ITERATIONS.'). Retrying patch synthesis.',
                        actorType: 'agent',
                        actorId: 'validation-agent',
                        metadata: [
                            'feedback' => $error?->message,
                            'attempt' => $currentAttempt,
                            'error_code' => $error?->code,
                        ],
                    );

                    GeneratePatchJob::dispatch($incident)->onQueue('incidents');
                } else {
                    $escalateReason = ($currentAttempt >= self::MAX_PATCH_ITERATIONS)
                        ? 'Exhausted maximum patch attempts ('.self::MAX_PATCH_ITERATIONS.'). Manual intervention required.'
                        : "Validation error [{$error?->code}]: ".($error?->message ?? 'Validation failed.');

                    $this->stateMachine->transition(
                        incident: $incident,
                        targetStatus: IncidentStatus::ESCALATED,
                        reason: $escalateReason,
                        actorType: 'agent',
                        actorId: 'validation-agent',
                        metadata: [
                            'error_code' => ($currentAttempt >= self::MAX_PATCH_ITERATIONS) ? AgentErrorDTO::MAX_ATTEMPTS_EXCEEDED : $error?->code,
                            'feedback' => $error?->message,
                            'attempts' => $currentAttempt,
                        ],
                    );
                }
            }
        });
    }

    /**
     * Track active locks held by the current execution thread to allow reentrancy.
     *
     * @var array<string, int>
     */
    protected static array $activeLocks = [];

    /**
     * Execute an orchestration operation wrapped in an atomic distributed lock.
     *
     * @throws IncidentConcurrentModificationException
     */
    protected function withLock(Incident $incident, Closure $callback): mixed
    {
        $lockKey = "incident:orchestrator:{$incident->id}";

        if (isset(self::$activeLocks[$lockKey])) {
            self::$activeLocks[$lockKey]++;
            try {
                $incident->refresh();

                return $callback();
            } finally {
                self::$activeLocks[$lockKey]--;
                if (self::$activeLocks[$lockKey] <= 0) {
                    unset(self::$activeLocks[$lockKey]);
                }
            }
        }

        $ttl = (int) config('patchops.locks.orchestrator_ttl_seconds', 30);
        $lock = Cache::lock($lockKey, $ttl);

        self::$activeLocks[$lockKey] = 1;

        try {
            $result = null;
            $acquired = $lock->get(function () use ($incident, $callback, &$result) {
                $incident->refresh();
                $result = $callback();

                return true;
            });

            if ($acquired === false) {
                throw new IncidentConcurrentModificationException(
                    "Could not acquire orchestration lock for incident [{$incident->incident_number} / {$incident->id}] due to concurrent execution."
                );
            }

            return $result;
        } finally {
            unset(self::$activeLocks[$lockKey]);
            optional($lock)->release();
        }
    }

    /**
     * Record agent error object into incident error history.
     */
    protected function recordError(Incident $incident, AgentResultDTO $result): void
    {
        if (! $result->error) {
            return;
        }

        $errorHistory = $incident->metadata['error_history'] ?? [];
        $errorHistory[] = array_merge($result->error->toArray(), [
            'timestamp' => now()->toIso8601String(),
            'metadata' => $result->metadata,
        ]);

        $incident->metadata = array_merge($incident->metadata ?? [], [
            'error_history' => $errorHistory,
        ]);
        $incident->save();
    }
}
