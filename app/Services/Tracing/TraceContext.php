<?php

namespace App\Services\Tracing;

use Illuminate\Support\Str;

class TraceContext
{
    protected static ?string $correlationId = null;

    protected static ?string $incidentId = null;

    protected static ?string $agentRunId = null;

    protected static ?string $sandboxId = null;

    protected static ?string $agentRole = null;

    /**
     * Initialize or update the hierarchical trace context.
     */
    public static function set(
        ?string $correlationId = null,
        ?string $incidentId = null,
        ?string $agentRunId = null,
        ?string $sandboxId = null,
        ?string $agentRole = null,
    ): void {
        self::$correlationId = $correlationId ?? self::$correlationId ?? ('corr_'.Str::ulid());
        if ($incidentId !== null) {
            self::$incidentId = $incidentId;
        }
        if ($agentRunId !== null) {
            self::$agentRunId = $agentRunId;
        }
        if ($sandboxId !== null) {
            self::$sandboxId = $sandboxId;
        }
        if ($agentRole !== null) {
            self::$agentRole = $agentRole;
        }
    }

    public static function getCorrelationId(): string
    {
        return self::$correlationId ??= ('corr_'.Str::ulid());
    }

    public static function getIncidentId(): ?string
    {
        return self::$incidentId;
    }

    public static function getAgentRunId(): ?string
    {
        return self::$agentRunId;
    }

    public static function getSandboxId(): ?string
    {
        return self::$sandboxId;
    }

    public static function getAgentRole(): ?string
    {
        return self::$agentRole;
    }

    /**
     * Export current trace context as an array for JSON-RPC _meta payloads.
     *
     * @return array{correlation_id: string, incident_id: ?string, agent_run_id: ?string, sandbox_id: ?string, agent_role: ?string}
     */
    public static function toArray(): array
    {
        return [
            'correlation_id' => self::getCorrelationId(),
            'incident_id' => self::$incidentId,
            'agent_run_id' => self::$agentRunId,
            'sandbox_id' => self::$sandboxId,
            'agent_role' => self::$agentRole,
        ];
    }

    /**
     * Clear the current trace context.
     */
    public static function clear(): void
    {
        self::$correlationId = null;
        self::$incidentId = null;
        self::$agentRunId = null;
        self::$sandboxId = null;
        self::$agentRole = null;
    }
}
