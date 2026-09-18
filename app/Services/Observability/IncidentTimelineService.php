<?php

namespace App\Services\Observability;

use App\Models\AgentRun;
use App\Models\AuditEvent;
use App\Models\Incident;
use App\Models\VerificationCheck;
use Illuminate\Support\Collection;

class IncidentTimelineService
{
    /**
     * Build an end-to-end chronological timeline for an incident.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildTimeline(Incident $incident): array
    {
        $events = new Collection;

        // 1. Audit events
        $auditEvents = AuditEvent::where('incident_id', $incident->id)->get();
        foreach ($auditEvents as $audit) {
            $events->push([
                'id' => $audit->id,
                'type' => 'AUDIT_EVENT',
                'action' => $audit->action,
                'actor_type' => $audit->actor_type,
                'actor_id' => $audit->actor_id,
                'resource' => $audit->resource_type ? class_basename($audit->resource_type).":#{$audit->resource_id}" : null,
                'timestamp' => $audit->created_at?->toISOString() ?? now()->toISOString(),
                'raw_timestamp' => $audit->created_at?->timestamp ?? time(),
                'metadata' => $audit->metadata ?? [],
            ]);
        }

        // 2. Agent runs
        $agentRuns = AgentRun::where('incident_id', $incident->id)->get();
        foreach ($agentRuns as $run) {
            $events->push([
                'id' => 'run_'.$run->id,
                'type' => 'AGENT_RUN',
                'action' => "agent.{$run->agent_type}.{$run->status}",
                'actor_type' => 'agent',
                'actor_id' => $run->agent_type,
                'resource' => "AgentRun:#{$run->id}",
                'timestamp' => $run->created_at?->toISOString() ?? now()->toISOString(),
                'raw_timestamp' => $run->created_at?->timestamp ?? time(),
                'metadata' => [
                    'agent_type' => $run->agent_type,
                    'status' => $run->status,
                    'model' => $run->model,
                    'iteration' => $run->iteration,
                    'input_tokens' => $run->input_tokens,
                    'output_tokens' => $run->output_tokens,
                    'duration_ms' => $run->duration_ms,
                ],
            ]);
        }

        // 3. Verification checks
        $checks = VerificationCheck::whereHas('remediationRun', function ($query) use ($incident) {
            $query->where('incident_id', $incident->id);
        })->get();

        foreach ($checks as $check) {
            $events->push([
                'id' => $check->id,
                'type' => 'VERIFICATION_CHECK',
                'action' => "verification.{$check->check_type->value}.{$check->status}",
                'actor_type' => 'verification_gate',
                'actor_id' => 'quality_engine',
                'resource' => "VerificationCheck:#{$check->id}",
                'timestamp' => $check->created_at?->toISOString() ?? now()->toISOString(),
                'raw_timestamp' => $check->created_at?->timestamp ?? time(),
                'metadata' => [
                    'check_type' => $check->check_type->value,
                    'status' => $check->status,
                    'exit_code' => $check->exit_code,
                    'target_endpoint' => $check->target_endpoint,
                    'duration_ms' => $check->duration_ms,
                    'evidence' => $check->evidence,
                ],
            ]);
        }

        return $events->sortBy('raw_timestamp')->values()->map(function ($item) {
            unset($item['raw_timestamp']);

            return $item;
        })->all();
    }
}
