<?php

namespace App\Services\Approval;

use App\Enums\IncidentStatus;
use App\Exceptions\Approval\InvalidApprovalException;
use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Models\QualityGateRun;

class ApprovalValidationService
{
    /**
     * Validates all system invariants before an operator can submit an approval.
     *
     * @throws InvalidApprovalException
     */
    public function assertCanBeApproved(Incident $incident, PatchArtifact $patch): QualityGateRun
    {
        $currentStatus = $incident->status instanceof IncidentStatus
            ? $incident->status->value
            : (string) $incident->status;

        // Invariant 1: State must be strictly AWAITING_APPROVAL
        if ($currentStatus !== IncidentStatus::AWAITING_APPROVAL->value) {
            throw new InvalidApprovalException("Incident [{$incident->id}] is not awaiting approval. Current status: {$currentStatus}");
        }

        // Invariant 2: Iteration ceiling guard
        if ($incident->patch_iterations > 3) {
            throw new InvalidApprovalException("Incident exceeds allowable patch iteration limits ({$incident->patch_iterations}/3).");
        }

        // Invariant 3: Patch artifact must belong to this incident
        if ((string) $patch->incident_id !== (string) $incident->id) {
            throw new InvalidApprovalException("Patch artifact [{$patch->id}] does not match Incident [{$incident->id}].");
        }

        // Invariant 4: Latest Quality Gate run must exist, match this patch, and be strictly PASSED
        /** @var QualityGateRun|null $latestRun */
        $latestRun = QualityGateRun::where('incident_id', $incident->id)
            ->where('patch_id', $patch->id)
            ->latest('created_at')
            ->first();

        if (! $latestRun || ! $latestRun->passed) {
            throw new InvalidApprovalException("No successful Quality Gate evaluation found for patch [{$patch->id}].");
        }

        return $latestRun;
    }
}
