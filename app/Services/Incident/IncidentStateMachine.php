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
     * Transition an incident to a target status with atomic database transaction,
     * row-level locking, invariant validation, history logging, and post-commit event dispatching.
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
        return DB::transaction(function () use ($incident, $targetStatus, $reason, $actorType, $actorId, $metadata): Incident {
            // 1. Lock Incident row for update
            /** @var Incident $lockedIncident */
            $lockedIncident = Incident::where('id', $incident->id)->lockForUpdate()->firstOrFail();

            $currentStatus = $lockedIncident->status instanceof IncidentStatus
                ? $lockedIncident->status
                : (IncidentStatus::tryFrom((string) $lockedIncident->status) ?? IncidentStatus::RECEIVED);

            // 2. Validate Transition Guard
            if (! $currentStatus->canTransitionTo($targetStatus)) {
                throw new InvalidIncidentStatusTransitionException(
                    incident: $lockedIncident,
                    currentStatus: $currentStatus,
                    targetStatus: $targetStatus,
                );
            }

            $fromStatus = $currentStatus;

            // 3. Lifecycle hooks & timestamps
            if ($targetStatus === IncidentStatus::RESOLVED && $lockedIncident->resolved_at === null) {
                $lockedIncident->resolved_at = now();
            } elseif ($targetStatus === IncidentStatus::CLOSED && $lockedIncident->resolved_at === null) {
                $lockedIncident->resolved_at = now();
            }

            $lockedIncident->status = $targetStatus;
            $lockedIncident->save();

            // 4. Insert immutable transition history record
            $lockedIncident->transitions()->create([
                'from_status' => $fromStatus,
                'to_status' => $targetStatus,
                'reason' => $reason,
                'actor_type' => $actorType,
                'actor_id' => $actorId ?? (auth()->check() ? (string) auth()->id() : 'system'),
                'correlation_id' => $lockedIncident->correlation_id ?? (request()->header('X-Correlation-ID') ?: (string) Str::ulid()),
                'metadata' => $metadata,
            ]);

            // 5. Insert audit event record
            AuditLogger::logSystemAction(
                event: 'incident.status_changed',
                auditable: $lockedIncident,
                payload: array_merge([
                    'incident_id' => $lockedIncident->id,
                    'incident_number' => $lockedIncident->incident_number,
                    'from_status' => $fromStatus->value,
                    'to_status' => $targetStatus->value,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'reason' => $reason,
                ], $metadata),
                correlationId: $lockedIncident->correlation_id,
            );

            // 6. Sync original model instance attributes
            $incident->fill($lockedIncident->getAttributes());
            $incident->syncOriginal();

            // 7. Dispatch domain event after transaction successfully commits
            DB::afterCommit(function () use ($lockedIncident, $fromStatus, $targetStatus, $reason, $metadata) {
                IncidentStatusChanged::dispatch($lockedIncident, $fromStatus, $targetStatus, $reason, $metadata);
            });

            return $lockedIncident;
        });
    }
}
