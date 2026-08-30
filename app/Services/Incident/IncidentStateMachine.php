<?php

namespace App\Services\Incident;

use App\Enums\IncidentStatus;
use App\Events\IncidentStatusChanged;
use App\Exceptions\InvalidIncidentStatusTransitionException;
use App\Models\Incident;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class IncidentStateMachine
{
    /**
     * Transition an incident to a target status with validation, hooks, event dispatching, and audit logging.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidIncidentStatusTransitionException
     */
    public function transition(
        Incident $incident,
        IncidentStatus $targetStatus,
        ?string $reason = null,
        array $context = [],
    ): Incident {
        $currentStatus = $incident->status instanceof IncidentStatus
            ? $incident->status
            : (IncidentStatus::tryFrom((string) $incident->status) ?? IncidentStatus::RECEIVED);

        if (! $currentStatus->canTransitionTo($targetStatus)) {
            throw new InvalidIncidentStatusTransitionException(
                incident: $incident,
                currentStatus: $currentStatus,
                targetStatus: $targetStatus,
            );
        }

        return DB::transaction(function () use ($incident, $currentStatus, $targetStatus, $reason, $context): Incident {
            $fromStatus = $currentStatus;

            // Lifecycle hooks & state invariants
            if ($targetStatus === IncidentStatus::RESOLVED && $incident->resolved_at === null) {
                $incident->resolved_at = now();
            } elseif ($targetStatus === IncidentStatus::CLOSED && $incident->resolved_at === null) {
                $incident->resolved_at = now();
            }

            $incident->status = $targetStatus;
            $incident->save();

            // Dispatch domain event
            IncidentStatusChanged::dispatch($incident, $fromStatus, $targetStatus, $reason, $context);

            // Record audit log entry
            AuditLogger::logSystemAction(
                event: 'incident.status_changed',
                auditable: $incident,
                payload: array_merge([
                    'incident_id' => $incident->id,
                    'incident_number' => $incident->incident_number,
                    'from_status' => $fromStatus->value,
                    'to_status' => $targetStatus->value,
                    'reason' => $reason,
                ], $context),
                correlationId: $incident->correlation_id,
            );

            return $incident;
        });
    }
}
