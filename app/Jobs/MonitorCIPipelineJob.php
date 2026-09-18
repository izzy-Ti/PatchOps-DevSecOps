<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Enums\RemediationStatus;
use App\Models\Incident;
use App\Models\RemediationRun;
use App\Services\AuditLogger;
use App\Services\CICD\CIPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MonitorCIPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public Incident $incident,
        public RemediationRun $run,
        public array $context = [],
    ) {
        $this->onQueue('incidents');
    }

    public function handle(CIPipelineService $ciService): void
    {
        Log::withContext(['correlation_id' => $this->incident->correlation_id]);
        Log::info("MonitorCIPipelineJob: Monitoring external CI for incident [{$this->incident->incident_number}].");

        if ($this->incident->status->canTransitionTo(IncidentStatus::CI_RUNNING)) {
            $this->incident->update(['status' => IncidentStatus::CI_RUNNING]);
        }

        $this->run->update(['status' => RemediationStatus::CI_RUNNING]);

        $result = $ciService->monitor($this->incident, $this->run, $this->context);

        AuditLogger::logSystemAction(
            event: 'remediation.ci_monitored',
            auditable: $this->incident,
            payload: [
                'incident_id' => $this->incident->id,
                'remediation_run_id' => $this->run->id,
                'ci_run_id' => $result->runId,
                'passed' => $result->passed,
                'failed_jobs' => $result->failedJobs,
            ],
            correlationId: $this->incident->correlation_id,
        );

        if ($result->passed) {
            Log::info("MonitorCIPipelineJob: CI Pipeline passed for incident [{$this->incident->incident_number}]. Progressing to merge & deployment.");

            if ($this->incident->status->canTransitionTo(IncidentStatus::READY_FOR_MERGE)) {
                $this->incident->update(['status' => IncidentStatus::READY_FOR_MERGE]);
            }

            // In our automated pipeline flow, after readiness for merge is achieved, transition to MERGED then dispatch deployment monitoring
            if ($this->incident->status->canTransitionTo(IncidentStatus::MERGED)) {
                $this->incident->update(['status' => IncidentStatus::MERGED]);
            }

            MonitorDeploymentJob::dispatch($this->incident, $this->run, $this->context);
        } else {
            // Count iterations
            $iterations = (int) ($this->context['iteration'] ?? $this->incident->agentRuns()->count() ?? 1);

            Log::warning("MonitorCIPipelineJob: CI Pipeline failed for incident [{$this->incident->incident_number}] (iteration: {$iterations}).");

            if ($iterations < 3) {
                if ($this->incident->status->canTransitionTo(IncidentStatus::PATCHING)) {
                    $this->incident->update(['status' => IncidentStatus::PATCHING]);
                }
                // Loop back for remediation iteration
                Log::info("MonitorCIPipelineJob: Iteration {$iterations} < 3, looping back to remediation.");
            } else {
                Log::error("MonitorCIPipelineJob: Iteration limit {$iterations} reached. Escalating incident [{$this->incident->incident_number}].");
                if ($this->incident->status->canTransitionTo(IncidentStatus::ESCALATED)) {
                    $this->incident->update(['status' => IncidentStatus::ESCALATED]);
                }
                $this->run->update(['status' => RemediationStatus::ESCALATED]);
            }
        }
    }
}
