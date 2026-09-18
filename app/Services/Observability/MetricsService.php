<?php

namespace App\Services\Observability;

use App\Enums\IncidentStatus;
use App\Models\AgentRun;
use App\Models\Incident;
use App\Models\VerificationCheck;

class MetricsService
{
    /**
     * Compute system operational metrics including MTTR, success rates, and token consumption.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        $totalIncidents = Incident::count();
        $remediatedIncidents = Incident::where('status', IncidentStatus::REMEDIATED)
            ->whereNotNull('remediated_at')
            ->get();

        $remediatedCount = $remediatedIncidents->count();

        // Calculate MTTR in seconds
        $mttrSeconds = 0.0;
        if ($remediatedCount > 0) {
            $totalDuration = $remediatedIncidents->reduce(function (float $carry, Incident $incident) {
                $created = $incident->created_at?->getTimestamp() ?? 0;
                $remediated = $incident->remediated_at?->getTimestamp() ?? $created;

                return $carry + max(0, $remediated - $created);
            }, 0.0);

            $mttrSeconds = round($totalDuration / $remediatedCount, 2);
        }

        // Agent telemetry
        $totalAgentRuns = AgentRun::count();
        $totalInputTokens = (int) AgentRun::sum('input_tokens');
        $totalOutputTokens = (int) AgentRun::sum('output_tokens');
        $avgDurationMs = (float) AgentRun::avg('duration_ms');

        // Verification checks
        $totalChecks = VerificationCheck::count();
        $passedChecks = VerificationCheck::where('status', 'PASSED')->count();
        $checkPassRate = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 2) : 100.0;

        return [
            'incidents' => [
                'total' => $totalIncidents,
                'remediated' => $remediatedCount,
                'remediation_rate_percent' => $totalIncidents > 0 ? round(($remediatedCount / $totalIncidents) * 100, 2) : 0.0,
                'mttr_seconds' => $mttrSeconds,
                'mttr_minutes' => round($mttrSeconds / 60, 2),
            ],
            'agent_performance' => [
                'total_runs' => $totalAgentRuns,
                'total_tokens' => $totalInputTokens + $totalOutputTokens,
                'input_tokens' => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
                'average_duration_ms' => round($avgDurationMs, 2),
            ],
            'verification_checks' => [
                'total' => $totalChecks,
                'passed' => $passedChecks,
                'failed' => $totalChecks - $passedChecks,
                'pass_rate_percent' => $checkPassRate,
            ],
        ];
    }
}
