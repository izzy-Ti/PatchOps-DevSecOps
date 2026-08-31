<?php

namespace App\Services\Orchestration;

use App\Agents\ReproductionAgent;
use App\DTOs\ReproductionResultDTO;
use App\Enums\IncidentStatus;
use App\Jobs\DispatchPatchAgentJob;
use App\Jobs\ExecuteReproductionJob;
use App\Models\Incident;
use App\Services\Incident\IncidentStateMachine;
use Illuminate\Support\Facades\Log;
use Throwable;

class IncidentOrchestrator
{
    public function __construct(
        protected ReproductionAgent $reproductionAgent,
        protected ?IncidentStateMachine $stateMachine = null,
    ) {
        $this->stateMachine ??= app(IncidentStateMachine::class);
    }

    /**
     * Handle reproduction workflow for a prioritized incident.
     */
    public function handlePrioritized(Incident $incident): void
    {
        $incident->refresh();

        // 1. Transition state to REPRODUCING
        if ($incident->status !== IncidentStatus::REPRODUCING && $incident->status->canTransitionTo(IncidentStatus::REPRODUCING)) {
            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::REPRODUCING,
                reason: 'Reproduction pipeline initiated.',
                actorType: 'orchestrator',
                actorId: 'orchestrator',
            );
        }

        try {
            // 2. Execute Reproduction Agent ReAct loop
            $result = $this->reproductionAgent->execute($incident);

            // 3. Process structured result contract
            if ($result->isReproduced()) {
                $meta = is_array($incident->metadata) ? $incident->metadata : (is_string($incident->metadata) ? (json_decode($incident->metadata, true) ?? []) : []);
                $incident->metadata = array_merge($meta, [
                    'reproduction_result' => $result->toArray(),
                    'reproduction_metadata' => $result->toArray(),
                ]);
                $incident->save();

                if ($incident->status->canTransitionTo(IncidentStatus::REPRODUCED)) {
                    $this->stateMachine->transition(
                        incident: $incident,
                        targetStatus: IncidentStatus::REPRODUCED,
                        reason: 'Vulnerability successfully reproduced.',
                        actorType: 'agent',
                        actorId: 'reproduction-agent',
                        metadata: $result->toArray(),
                    );
                }

                Log::info("Vulnerability successfully reproduced for Incident [{$incident->incident_number}]. Dispatching Patch Agent.");

                // 4. Trigger next pipeline phase
                DispatchPatchAgentJob::dispatch($incident);
            } else {
                $this->handleNonReproducible($incident, $result);
            }
        } catch (Throwable $e) {
            $this->handleReproductionFailure($incident, $e);
        }
    }

    /**
     * Handle unverified or non-reproducible vulnerability outcome.
     */
    public function handleNonReproducible(Incident $incident, ReproductionResultDTO $result): void
    {
        $meta = is_array($incident->metadata) ? $incident->metadata : (is_string($incident->metadata) ? (json_decode($incident->metadata, true) ?? []) : []);
        $incident->metadata = array_merge($meta, [
            'reproduction_metadata' => $result->toArray(),
            'reproduction_result' => $result->toArray(),
        ]);
        $incident->save();

        if ($incident->status->canTransitionTo(IncidentStatus::TRIAGED_NOT_REPRODUCIBLE)) {
            $this->stateMachine->transition(
                incident: $incident,
                targetStatus: IncidentStatus::TRIAGED_NOT_REPRODUCIBLE,
                reason: 'Reproduction unverified or exploit failed to trigger.',
                actorType: 'agent',
                actorId: 'reproduction-agent',
                metadata: $result->toArray(),
            );
        }

        Log::warning("Reproduction unverified for Incident [{$incident->incident_number}]. Marked not reproducible.");
    }

    /**
     * Handle reproduction exception or failure with automated retries.
     */
    public function handleReproductionFailure(Incident $incident, Throwable $e): void
    {
        $meta = is_array($incident->metadata) ? $incident->metadata : (is_string($incident->metadata) ? (json_decode($incident->metadata, true) ?? []) : []);
        $currentRetries = (int) ($meta['reproduction_retries'] ?? 0) + 1;

        $incident->metadata = array_merge($meta, [
            'reproduction_retries' => $currentRetries,
            'last_reproduction_error' => $e->getMessage(),
        ]);
        $incident->save();

        if ($currentRetries < 3) {
            Log::warning("Reproduction failed for Incident [{$incident->incident_number}] (Attempt {$currentRetries}/3). Retrying...");

            if ($incident->status->canTransitionTo(IncidentStatus::PRIORITIZED)) {
                $this->stateMachine->transition(
                    incident: $incident,
                    targetStatus: IncidentStatus::PRIORITIZED,
                    reason: "Reproduction attempt {$currentRetries}/3 failed. Re-queuing.",
                    actorType: 'orchestrator',
                    actorId: 'orchestrator',
                    metadata: ['retry_attempt' => $currentRetries],
                );
            }

            ExecuteReproductionJob::dispatch($incident);
        } else {
            Log::error("Reproduction exhausted max retries for Incident [{$incident->incident_number}]. Escalating to security engineering.");

            if ($incident->status->canTransitionTo(IncidentStatus::ESCALATED)) {
                $this->stateMachine->transition(
                    incident: $incident,
                    targetStatus: IncidentStatus::ESCALATED,
                    reason: "Reproduction exhausted max retries (3): {$e->getMessage()}",
                    actorType: 'orchestrator',
                    actorId: 'orchestrator',
                    metadata: ['escalation_reason' => $e->getMessage(), 'retries' => $currentRetries],
                );
            }
        }
    }
}
