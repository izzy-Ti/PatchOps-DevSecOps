<?php

namespace App\Listeners;

use App\Events\IncidentStatusChanged;
use App\Events\RemediationCompleted;
use App\Models\AuditEvent;
use App\Services\Security\SecretRedactionService;
use Illuminate\Support\Facades\Log;

class AuditEventListener
{
    public function __construct(
        protected SecretRedactionService $redactionService,
    ) {}

    /**
     * Handle incoming domain events and persist immutable audit trail records.
     */
    public function handle(object $event): void
    {
        try {
            if ($event instanceof IncidentStatusChanged) {
                AuditEvent::create([
                    'incident_id' => $event->incident->id,
                    'actor_type' => 'system',
                    'actor_id' => 'orchestrator',
                    'action' => 'incident.status_changed',
                    'resource_type' => $event->incident->getMorphClass(),
                    'resource_id' => (string) $event->incident->getKey(),
                    'metadata' => $this->redactionService->redact([
                        'from_status' => $event->fromStatus->value,
                        'to_status' => $event->toStatus->value,
                        'reason' => $event->reason,
                        'context' => $event->context,
                    ]),
                    'ip_address' => app()->runningInConsole() ? null : request()?->ip(),
                ]);
            } elseif ($event instanceof RemediationCompleted) {
                AuditEvent::create([
                    'incident_id' => $event->incident->id,
                    'actor_type' => 'system',
                    'actor_id' => 'remediation_pipeline',
                    'action' => 'remediation.completed',
                    'resource_type' => $event->run->getMorphClass(),
                    'resource_id' => (string) $event->run->getKey(),
                    'metadata' => $this->redactionService->redact([
                        'incident_number' => $event->incident->incident_number,
                        'remediation_run_id' => $event->run->id,
                        'status' => $event->run->status->value,
                        'environment' => $event->run->environment,
                    ]),
                    'ip_address' => null,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("AuditEventListener failed to persist audit event: {$e->getMessage()}");
        }
    }
}
