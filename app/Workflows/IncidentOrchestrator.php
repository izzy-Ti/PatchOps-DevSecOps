<?php

namespace App\Workflows;

use App\Enums\IncidentStatus;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\GeneratePatchJob;
use App\Jobs\HandleIncidentFailureJob;
use App\Jobs\ReproduceVulnerabilityJob;
use App\Jobs\TriageIncidentJob;
use App\Jobs\ValidatePatchJob;
use App\Models\Incident;

class IncidentOrchestrator
{
    /**
     * Inspect incident status and dispatch corresponding asynchronous workflow job.
     */
    public function handle(Incident $incident): void
    {
        $status = $incident->status instanceof IncidentStatus
            ? $incident->status
            : (IncidentStatus::tryFrom((string) $incident->status) ?? IncidentStatus::RECEIVED);

        match ($status) {
            IncidentStatus::RECEIVED => TriageIncidentJob::dispatch($incident),
            IncidentStatus::PRIORITIZED => ReproduceVulnerabilityJob::dispatch($incident),
            IncidentStatus::REPRODUCED => GeneratePatchJob::dispatch($incident),
            IncidentStatus::PATCHING,
            IncidentStatus::VALIDATING => ValidatePatchJob::dispatch($incident),
            IncidentStatus::VERIFIED => CreatePullRequestJob::dispatch($incident),
            IncidentStatus::FAILED,
            IncidentStatus::ESCALATED => HandleIncidentFailureJob::dispatch($incident),
            IncidentStatus::AWAITING_APPROVAL,
            IncidentStatus::TRIAGING,
            IncidentStatus::REPRODUCING,
            IncidentStatus::PR_CREATED,
            IncidentStatus::CI_RUNNING,
            IncidentStatus::RESOLVED,
            IncidentStatus::CLOSED => null,
        };
    }
}
