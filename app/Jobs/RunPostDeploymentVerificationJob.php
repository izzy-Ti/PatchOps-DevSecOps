<?php

namespace App\Jobs;

use App\Enums\IncidentStatus;
use App\Enums\RemediationStatus;
use App\Events\RemediationCompleted;
use App\Models\Incident;
use App\Models\RemediationRun;
use App\Services\AuditLogger;
use App\Services\Verification\HealthVerificationService;
use App\Services\Verification\SecurityVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunPostDeploymentVerificationJob implements ShouldQueue
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

    public function handle(
        HealthVerificationService $healthService,
        SecurityVerificationService $securityService
    ): void {
        Log::withContext(['correlation_id' => $this->incident->correlation_id]);
        Log::info("RunPostDeploymentVerificationJob: Executing health and exploit reverification for incident [{$this->incident->incident_number}].");

        if ($this->incident->status->canTransitionTo(IncidentStatus::POST_DEPLOY_VERIFY)) {
            $this->incident->update(['status' => IncidentStatus::POST_DEPLOY_VERIFY]);
        }

        $this->run->update(['status' => RemediationStatus::VERIFYING]);

        $healthResult = $healthService->verify($this->incident, $this->run, $this->context);
        $securityResult = $securityService->verify($this->incident, $this->run, $this->context);

        $passed = $healthResult->passed && $securityResult->passed;

        AuditLogger::logSystemAction(
            event: 'remediation.post_deploy_verified',
            auditable: $this->incident,
            payload: [
                'incident_id' => $this->incident->id,
                'remediation_run_id' => $this->run->id,
                'health_passed' => $healthResult->passed,
                'health_status_code' => $healthResult->statusCode,
                'security_passed' => $securityResult->passed,
                'exploit_blocked' => $securityResult->exploitBlocked,
            ],
            correlationId: $this->incident->correlation_id,
        );

        if ($passed) {
            Log::info("RunPostDeploymentVerificationJob: Verification successful. Closing incident [{$this->incident->incident_number}] as REMEDIATED.");

            $this->run->update([
                'status' => RemediationStatus::REMEDIATED,
            ]);

            if ($this->incident->status->canTransitionTo(IncidentStatus::REMEDIATED)) {
                $this->incident->update([
                    'status' => IncidentStatus::REMEDIATED,
                    'remediated_at' => now(),
                ]);
            }

            // Fire event if class exists
            if (class_exists(RemediationCompleted::class)) {
                event(new RemediationCompleted($this->incident, $this->run));
            }
        } else {
            Log::error("RunPostDeploymentVerificationJob: Verification failed for incident [{$this->incident->incident_number}]. Requesting rollback and escalating.");

            $this->run->update([
                'status' => RemediationStatus::FAILED,
            ]);

            if ($this->incident->status->canTransitionTo(IncidentStatus::ESCALATED)) {
                $this->incident->update(['status' => IncidentStatus::ESCALATED]);
            }

            AuditLogger::logSystemAction(
                event: 'remediation.rollback_requested',
                auditable: $this->incident,
                payload: [
                    'incident_id' => $this->incident->id,
                    'remediation_run_id' => $this->run->id,
                    'health_passed' => $healthResult->passed,
                    'security_passed' => $securityResult->passed,
                    'action' => 'escalate_for_rollback',
                ],
                correlationId: $this->incident->correlation_id,
            );
        }
    }
}
