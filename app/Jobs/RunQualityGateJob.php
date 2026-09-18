<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\Incident\IncidentStateMachine;
use App\Services\QualityGate\QualityGateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunQualityGateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public Incident $incident,
        public PatchArtifact $patch,
        public array $context = [],
    ) {
        $this->onQueue('incidents');
    }

    public function handle(QualityGateService $service, IncidentStateMachine $stateMachine): void
    {
        Log::info("RunQualityGateJob: Running deterministic Quality Gate for incident [{$this->incident->incident_number}] on patch [{$this->patch->id}].");

        if ($this->incident->status !== IncidentStatus::VALIDATING) {
            $stateMachine->transition(
                incident: $this->incident,
                targetStatus: IncidentStatus::VALIDATING,
                reason: 'Deterministic Quality Gate evaluation started',
                actorType: 'system',
            );
        }

        $result = $service->evaluate($this->incident, $this->patch, $this->context);

        if ($result->passed) {
            $this->patch->update(['status' => 'verified']);

            $stateMachine->transition(
                incident: $this->incident,
                targetStatus: IncidentStatus::AWAITING_APPROVAL,
                reason: 'Quality Gate passed: All technical verification checks satisfied.',
                actorType: 'system',
                metadata: [
                    'patch_id' => $this->patch->id,
                    'total_checks' => $result->totalChecks(),
                    'duration_ms' => $result->durationMs,
                ],
            );
        } else {
            $this->patch->update(['status' => 'rejected']);
            $currentIterations = (int) ($this->incident->patch_iterations ?? 1);
            $failedCheckName = $result->failedCheck?->name ?? 'unknown_check';

            if ($currentIterations >= 3) {
                $escalationReason = "Quality Gate failure on attempt {$currentIterations}/3: [{$failedCheckName}]. Iteration ceiling reached.";
                $this->incident->escalation_reason = $escalationReason;
                $this->incident->save();

                $stateMachine->transition(
                    incident: $this->incident,
                    targetStatus: IncidentStatus::ESCALATED,
                    reason: $escalationReason,
                    actorType: 'system',
                    metadata: [
                        'failure_evidence' => $result->failureEvidence,
                        'failed_check' => $failedCheckName,
                        'iterations' => $currentIterations,
                    ],
                );
            } else {
                $this->incident->incrementPatchIterations();

                $stateMachine->transition(
                    incident: $this->incident,
                    targetStatus: IncidentStatus::PATCHING,
                    reason: "Quality Gate check [{$failedCheckName}] failed on attempt {$currentIterations}/3. Initiating patch repair loop.",
                    actorType: 'system',
                    metadata: [
                        'failure_evidence' => $result->failureEvidence,
                        'failed_check' => $failedCheckName,
                    ],
                );

                DispatchPatchAgentJob::dispatch($this->incident, $result->failureEvidence)->onQueue('incidents');
            }
        }
    }
}
