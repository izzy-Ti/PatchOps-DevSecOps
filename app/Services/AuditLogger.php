<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * Resolve the active correlation ID from parameter, container binding, request header, or generate a new one.
     */
    public static function resolveCorrelationId(?string $correlationId = null): string
    {
        if (! empty($correlationId)) {
            return $correlationId;
        }

        if (app()->bound('correlation_id')) {
            return (string) app('correlation_id');
        }

        if (! app()->runningInConsole() && request()?->header('X-Correlation-ID')) {
            return (string) request()->header('X-Correlation-ID');
        }

        $generated = 'INC-'.strtoupper(Str::random(8));
        app()->instance('correlation_id', $generated);

        return $generated;
    }

    /**
     * Record an audit log for an action performed by a user.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function logUserAction(
        User $user,
        string $event,
        ?Model $auditable = null,
        array $payload = [],
        ?string $ip = null,
        ?string $correlationId = null,
    ): AuditLog {
        return AuditLog::create([
            'correlation_id' => static::resolveCorrelationId($correlationId),
            'actor_type' => 'user',
            'actor_id' => $user->getKey(),
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'payload' => $payload,
            'ip_address' => $ip ?? (app()->runningInConsole() ? null : request()?->ip()),
        ]);
    }

    /**
     * Record an audit log for an action performed by an AI agent.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function logAgentAction(
        string $agentName,
        string $event,
        ?Model $auditable = null,
        array $payload = [],
        ?string $correlationId = null,
    ): AuditLog {
        return AuditLog::create([
            'correlation_id' => static::resolveCorrelationId($correlationId),
            'actor_type' => 'agent',
            'actor_id' => null,
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'payload' => array_merge(['agent' => $agentName], $payload),
            'ip_address' => null,
        ]);
    }

    /**
     * Record an audit log for an automated system action.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function logSystemAction(
        string $event,
        ?Model $auditable = null,
        array $payload = [],
        ?string $correlationId = null,
    ): AuditLog {
        $log = AuditLog::create([
            'correlation_id' => static::resolveCorrelationId($correlationId),
            'actor_type' => 'system',
            'actor_id' => null,
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'payload' => $payload,
            'ip_address' => null,
        ]);

        static::recordImmutableAuditEvent(
            actorType: 'system',
            actorId: 'system',
            action: $event,
            auditable: $auditable,
            payload: $payload,
            ip: null,
        );

        return $log;
    }

    /**
     * Persist to immutable audit_events table when an incident or auditable is present.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function recordImmutableAuditEvent(
        string $actorType,
        ?string $actorId,
        string $action,
        ?Model $auditable,
        array $payload,
        ?string $ip = null,
    ): void {
        try {
            $incidentId = null;
            if ($auditable instanceof \App\Models\Incident) {
                $incidentId = $auditable->id;
            } elseif (isset($payload['incident_id'])) {
                $incidentId = $payload['incident_id'];
            } elseif ($auditable && method_exists($auditable, 'incident') && $auditable->incident_id) {
                $incidentId = $auditable->incident_id;
            }

            if ($incidentId) {
                $redactor = app(\App\Services\Security\SecretRedactionService::class);
                \App\Models\AuditEvent::create([
                    'incident_id' => $incidentId,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'action' => $action,
                    'resource_type' => $auditable?->getMorphClass(),
                    'resource_id' => (string) $auditable?->getKey(),
                    'metadata' => $redactor->redact($payload),
                    'ip_address' => $ip,
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Could not persist to audit_events: {$e->getMessage()}");
        }
    }
}

