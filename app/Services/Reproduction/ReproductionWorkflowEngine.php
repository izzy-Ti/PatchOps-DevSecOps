<?php

namespace App\Services\Reproduction;

use App\Agents\ReproductionAgent;
use App\DTOs\ReproductionResultDTO;
use App\Models\Incident;
use App\Models\Sandbox;
use Illuminate\Support\Facades\Log;

class ReproductionWorkflowEngine
{
    public function __construct(
        protected ReproductionAgent $agent,
    ) {}

    /**
     * Coordinate the end-to-end vulnerability reproduction workflow.
     */
    public function run(Incident $incident, ?int $agentRunId = null): ReproductionResultDTO
    {
        Log::info("ReproductionWorkflowEngine: Initiating reproduction cycle for incident [{$incident->incident_number}].");

        $resultDto = $this->agent->execute($incident, $agentRunId);

        // Ensure active sandboxes for this incident have their hard expiration ceiling tracked
        if (! empty($incident->metadata['sandbox_workspace_id'])) {
            $sandboxId = $incident->metadata['sandbox_workspace_id'];
            Sandbox::firstOrCreate(
                ['sandbox_id' => $sandboxId],
                [
                    'incident_id' => $incident->id,
                    'runtime' => $incident->metadata['sandbox_ecosystem'] ?? 'node',
                    'status' => 'active',
                    'repository' => $incident->repository,
                    'expires_at' => now()->addMinutes(10),
                ]
            );
        }

        return $resultDto;
    }
}
