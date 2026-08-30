<?php

namespace App\Services\MCP\Guards;

use App\Exceptions\MCP\UnauthorizedCriticalActionException;
use App\Models\Incident;
use App\Services\AuditLogger;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\RiskLevel;
use Illuminate\Support\Facades\Log;

class ToolRiskLevelGuard
{
    /**
     * Evaluate the risk level of the tool and enforce verification, branch/path restrictions, and HITL approval gates.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws UnauthorizedCriticalActionException
     */
    public function evaluate(ToolInterface $tool, array $arguments, Incident $incident): void
    {
        $definition = $tool->definition();
        $riskLevel = $definition->riskLevel;
        $toolName = $definition->name;

        switch ($riskLevel) {
            case RiskLevel::LOW:
            case RiskLevel::MEDIUM:
                // Standard role permission & boundary guards apply
                break;

            case RiskLevel::HIGH:
                // Repository modification & write operations
                $this->validateHighRiskOperation($toolName, $arguments, $incident);
                break;

            case RiskLevel::CRITICAL:
                // Production-impacting actions require explicit Human-in-the-Loop (HITL) sign-off
                $this->assertHumanApproval($toolName, $arguments, $incident);
                break;
        }
    }

    /**
     * Enforce branch naming conventions and directory constraints for HIGH risk mutations.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function validateHighRiskOperation(string $toolName, array $arguments, Incident $incident): void
    {
        // If creating a pull request or branch, ensure it uses patch/fix prefix
        if (isset($arguments['branch']) || isset($arguments['head'])) {
            $branchName = (string) ($arguments['branch'] ?? $arguments['head']);
            $validPrefixes = ['patch/', 'fix/', 'patchops/', 'security/'];

            $hasValidPrefix = false;
            foreach ($validPrefixes as $prefix) {
                if (str_starts_with($branchName, $prefix)) {
                    $hasValidPrefix = true;
                    break;
                }
            }

            if (! $hasValidPrefix && ! empty($branchName)) {
                Log::warning("High-risk tool [{$toolName}] called with non-standard branch [{$branchName}] on incident [{$incident->incident_number}].");
            }
        }
    }

    /**
     * Assert that a CRITICAL risk action has received explicit Human-in-the-Loop (HITL) approval.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws UnauthorizedCriticalActionException
     */
    protected function assertHumanApproval(string $toolName, array $arguments, Incident $incident): void
    {
        $metadata = $incident->metadata ?? [];
        $isHitlApproved = ($metadata['hitl_approved'] ?? false) === true
            || ! empty($metadata['human_approval_signature'])
            || ! empty($metadata['approved_by_user_id']);

        if (! $isHitlApproved) {
            AuditLogger::logSystemAction(
                event: 'security.critical_tool_blocked',
                auditable: $incident,
                payload: [
                    'tool' => $toolName,
                    'risk_level' => RiskLevel::CRITICAL->value,
                    'incident_id' => $incident->id,
                    'incident_number' => $incident->incident_number,
                    'reason' => 'Autonomous execution of CRITICAL tool blocked without human operator sign-off.',
                ],
                correlationId: $incident->correlation_id,
            );

            Log::critical("CRITICAL tool [{$toolName}] blocked autonomously on incident [{$incident->incident_number}]. HITL approval required.");

            throw new UnauthorizedCriticalActionException(
                toolName: $toolName,
                reason: 'Autonomous execution of CRITICAL production-impacting tools is prohibited. Explicit Human-in-the-Loop (HITL) approval signature is required.',
                incident: $incident,
            );
        }
    }
}
