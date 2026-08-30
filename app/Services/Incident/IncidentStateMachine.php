<?php

namespace App\Services\Incident;

use App\Enums\IncidentStatus;
use App\Events\IncidentStatusChanged;
use App\Exceptions\InvalidIncidentStatusTransitionException;
use App\Models\Incident;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IncidentStateMachine
{
    /**
     * Transition an incident to a target status with validation, history logging, event dispatching, and audit logging.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws InvalidIncidentStatusTransitionException
     */
    public function transition(
        Incident $incident,
        IncidentStatus $targetStatus,
        ?string $reason = null,
        string $actorType = 'system',
        ?string $actorId = null,
        array $metadata = [],
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

        return DB::transaction(function () use ($incident, $currentStatus, $targetStatus, $reason, $actorType, $actorId, $metadata): Incident {
            $fromStatus = $currentStatus;

            // Lifecycle hooks & state invariants
            if ($targetStatus === IncidentStatus::RESOLVED && $incident->resolved_at === null) {
                $incident->resolved_at = now();
            } elseif ($targetStatus === IncidentStatus::CLOSED && $incident->resolved_at === null) {
                $incident->resolved_at = now();
            }

            // Write immutable transition history log
            $incident->transitions()->create([
                'from_status' => $fromStatus,
                'to_status' => $targetStatus,
                'reason' => $reason,
                'actor_type' => $actorType,
                'actor_id' => $actorId ?? (auth()->check() ? (string) auth()->id() : 'system'),
                'correlation_id' => $incident->correlation_id ?? (request()->header('X-Correlation-ID') ?: (string) Str::ulid()),
                'metadata' => $metadata,
            ]);

            $incident->status = $targetStatus;
            $incident->save();

            // Dispatch domain event
            IncidentStatusChanged::dispatch($incident, $fromStatus, $targetStatus, $reason, $metadata);

            // Record audit log entry
            AuditLogger::logSystemAction(
                event: 'incident.status_changed',
                auditable: $incident,
                payload: array_merge([
                    'incident_id' => $incident->id,
                    'incident_number' => $incident->incident_number,
                    'from_status' => $fromStatus->value,
                    'to_status' => $targetStatus->value,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'reason' => $reason,
                ], $metadata),
                correlationId: $incident->correlation_id,
            );

            return $incident;
        });
    }
}
