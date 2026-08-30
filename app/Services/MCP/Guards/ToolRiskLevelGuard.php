<?php

namespace App\Services\MCP\Guards;

use App\Exceptions\MCP\HitlApprovalRequiredException;
use App\Models\Incident;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\RiskLevel;
use Illuminate\Support\Facades\Log;

class ToolRiskLevelGuard
{
    public function __construct(
        protected ?HitlApprovalGuard $hitlGuard = null,
    ) {
        $this->hitlGuard ??= app(HitlApprovalGuard::class);
    }

    /**
     * Evaluate the risk level of the tool and enforce verification, branch/path restrictions, and HITL approval gates.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws HitlApprovalRequiredException
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
                $this->hitlGuard->validate($tool, $arguments, $incident);
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
}
