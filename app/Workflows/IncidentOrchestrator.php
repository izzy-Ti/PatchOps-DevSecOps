<?php

namespace App\Workflows;

use App\DTOs\TriageResultDTO;
use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\GeneratePatchJob;
use App\Jobs\HandleIncidentFailureJob;
use App\Jobs\ReproduceVulnerabilityJob;
use App\Jobs\TriageIncidentJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;
use App\Services\Incident\IncidentStateMachine;

class IncidentOrchestrator
{
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
            IncidentStatus::PRIORITIZED => ReproduceVulnerabilityJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::REPRODUCED => GeneratePatchJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::PATCHING,
            IncidentStatus::VALIDATING => ValidatePatchJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::VERIFIED => CreatePullRequestJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::FAILED,
            IncidentStatus::ESCALATED => HandleIncidentFailureJob::dispatch($incident)->onQueue('incidents'),
            IncidentStatus::AWAITING_APPROVAL,
            IncidentStatus::TRIAGING,
            IncidentStatus::REPRODUCING,
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

            ReproduceVulnerabilityJob::dispatch($incident)->onQueue('incidents');
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
}
