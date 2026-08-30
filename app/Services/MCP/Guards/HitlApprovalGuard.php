<?php

namespace App\Services\MCP\Guards;

use App\Enums\IncidentStatus;
use App\Exceptions\MCP\HitlApprovalRequiredException;
use App\Models\Incident;
use App\Services\AuditLogger;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\RiskLevel;
use Illuminate\Support\Facades\Log;

class HitlApprovalGuard
{
    /**
     * Evaluate tool execution against Human-In-The-Loop (HITL) requirements.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws HitlApprovalRequiredException
     */
    public function validate(ToolInterface $tool, array $arguments, Incident $incident): void
    {
        $definition = $tool->definition();
        $toolName = $definition->name;
        $riskLevel = $definition->riskLevel;

        if ($riskLevel !== RiskLevel::CRITICAL) {
            return;
        }

        $metadata = $incident->metadata ?? [];
        $isApproved = false;

        // 1. Check explicit HITL approval flag or signature in incident metadata
        if (! empty($metadata['hitl_approved']) && $metadata['hitl_approved'] === true) {
            $isApproved = true;
        } elseif (! empty($metadata['human_approval_signature']) || ! empty($metadata['approved_by_user_id'])) {
            $isApproved = true;
        }

        // 2. Check cryptographic approval token in arguments
        if (! empty($arguments['approval_token'])) {
            $expectedHash = hash_hmac('sha256', "hitl:{$incident->id}:{$toolName}", (string) config('app.key'));
            if (hash_equals($expectedHash, (string) $arguments['approval_token'])) {
                $isApproved = true;
            }
        }

        if (! $isApproved) {
            // Transition incident to AWAITING_APPROVAL state
            if ($incident->status !== IncidentStatus::AWAITING_APPROVAL) {
                try {
                    $incident->transitionTo(
                        targetStatus: IncidentStatus::AWAITING_APPROVAL,
                        reason: "Tool [{$toolName}] requires Human-In-The-Loop (HITL) authorization.",
                        actorType: 'system',
                        metadata: ['pending_tool' => $toolName],
                    );
                } catch (\Throwable) {
                    $incident->status = IncidentStatus::AWAITING_APPROVAL;
                    $incident->save();
                }
            }

            AuditLogger::logSystemAction(
                event: 'security.hitl_gate_triggered',
                auditable: $incident,
                payload: [
                    'tool' => $toolName,
                    'risk_level' => $riskLevel->value,
                    'incident_id' => $incident->id,
                    'incident_number' => $incident->incident_number,
                    'action_required' => 'Human operator authorization required.',
                ],
                correlationId: $incident->correlation_id,
            );

            Log::warning("HITL Gate: Paused tool [{$toolName}] on incident [{$incident->incident_number}] awaiting human approval.");

            throw new HitlApprovalRequiredException(
                toolName: $toolName,
                reason: 'This tool requires explicit Human-In-The-Loop (HITL) authorization before proceeding.',
                incident: $incident,
                requiredAction: 'operator_sign_off',
            );
        }
    }

    /**
     * Generate a valid HMAC approval token for an incident tool invocation.
     */
    public static function generateApprovalToken(Incident $incident, string $toolName): string
    {
        return hash_hmac('sha256', "hitl:{$incident->id}:{$toolName}", (string) config('app.key'));
    }
}
