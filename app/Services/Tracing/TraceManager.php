<?php

namespace App\Services\Tracing;

use App\Models\Incident;
use App\Models\ToolCall;
use App\Models\Trace;
use App\Services\Security\SecretRedactionService;
use Illuminate\Support\Facades\Log;

class TraceManager
{
    protected static ?string $currentTraceId = null;

    public function __construct(
        protected SecretRedactionService $redactionService,
    ) {}

    /**
     * Start a new distributed trace for an incident.
     */
    public function startTrace(Incident $incident, ?string $correlationId = null): Trace
    {
        $trace = Trace::create([
            'incident_id' => $incident->id,
            'correlation_id' => $correlationId ?? $incident->correlation_id,
            'status' => 'ACTIVE',
        ]);

        static::$currentTraceId = $trace->id;

        Log::info("TraceManager: Started new trace [{$trace->id}] for incident [{$incident->incident_number}].");

        return $trace;
    }

    /**
     * Set the current active trace identifier in context.
     */
    public function setTraceId(?string $traceId): void
    {
        static::$currentTraceId = $traceId;
    }

    /**
     * Get the current active trace ID.
     */
    public function currentTraceId(): ?string
    {
        return static::$currentTraceId;
    }

    /**
     * Retrieve the current active Trace model.
     */
    public function currentTrace(): ?Trace
    {
        if (! static::$currentTraceId) {
            return null;
        }

        return Trace::find(static::$currentTraceId);
    }

    /**
     * Complete an active trace.
     */
    public function completeTrace(?string $traceId = null, string $status = 'COMPLETED'): ?Trace
    {
        $id = $traceId ?? static::$currentTraceId;
        if (! $id) {
            return null;
        }

        $trace = Trace::find($id);
        if ($trace) {
            $trace->update([
                'status' => $status,
                'completed_at' => now(),
            ]);
            Log::info("TraceManager: Completed trace [{$id}] with status [{$status}].");
        }

        if (static::$currentTraceId === $id) {
            static::$currentTraceId = null;
        }

        return $trace;
    }

    /**
     * Record an MCP tool call with scrubbed secrets and latency.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordToolCall(array $data): ToolCall
    {
        $traceId = $data['trace_id'] ?? static::$currentTraceId;

        // Redact arguments and results
        $arguments = isset($data['arguments']) ? $this->redactionService->redact($data['arguments']) : [];
        $result = isset($data['result']) ? $this->redactionService->redact($data['result']) : null;
        $error = isset($data['error']) ? $this->redactionService->redactString((string) $data['error']) : null;

        return ToolCall::create([
            'agent_run_id' => $data['agent_run_id'] ?? null,
            'incident_id' => $data['incident_id'] ?? null,
            'trace_id' => $traceId,
            'tool_name' => (string) ($data['tool_name'] ?? 'unknown_tool'),
            'server_name' => (string) ($data['server_name'] ?? 'local'),
            'permission_scope' => $data['permission_scope'] ?? 'read',
            'risk_level' => $data['risk_level'] ?? 'low',
            'arguments' => $arguments,
            'result' => is_array($result) ? $result : ['output' => $result],
            'status' => $data['status'] ?? 'SUCCESS',
            'exit_code' => isset($data['exit_code']) ? (int) $data['exit_code'] : 0,
            'duration_ms' => isset($data['duration_ms']) ? (int) $data['duration_ms'] : 0,
            'error' => $error,
        ]);
    }
}
