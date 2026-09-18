<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Enums\RemediationStatus;
use App\Models\Incident;
use App\Models\RemediationRun;
use App\Services\AuditLogger;
use App\Services\Deployment\DeploymentMonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MonitorDeploymentJob implements ShouldQueue
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

    public function handle(DeploymentMonitoringService $deploymentService): void
    {
        Log::withContext(['correlation_id' => $this->incident->correlation_id]);
        Log::info("MonitorDeploymentJob: Tracking deployment for incident [{$this->incident->incident_number}].");

        if ($this->incident->status->canTransitionTo(IncidentStatus::DEPLOYING)) {
            $this->incident->update(['status' => IncidentStatus::DEPLOYING]);
        }

        $this->run->update(['status' => RemediationStatus::DEPLOYING]);

        $result = $deploymentService->monitor($this->incident, $this->run, $this->context);

        AuditLogger::logSystemAction(
            event: 'remediation.deployment_monitored',
            auditable: $this->incident,
            payload: [
                'incident_id' => $this->incident->id,
                'remediation_run_id' => $this->run->id,
                'deployment_id' => $result->deploymentId,
                'environment' => $result->environment,
                'deployed' => $result->deployed,
            ],
            correlationId: $this->incident->correlation_id,
        );

        if ($result->deployed) {
            Log::info("MonitorDeploymentJob: Deployment succeeded for incident [{$this->incident->incident_number}]. Progressing to post-deployment verification.");

            if ($this->incident->status->canTransitionTo(IncidentStatus::DEPLOYED)) {
                $this->incident->update(['status' => IncidentStatus::DEPLOYED]);
            }

            RunPostDeploymentVerificationJob::dispatch($this->incident, $this->run, $this->context);
        } else {
            Log::error("MonitorDeploymentJob: Deployment failed for incident [{$this->incident->incident_number}]. Escalating.");

            $this->run->update(['status' => RemediationStatus::FAILED]);

            if ($this->incident->status->canTransitionTo(IncidentStatus::ESCALATED)) {
                $this->incident->update(['status' => IncidentStatus::ESCALATED]);
            }
        }
    }
}
