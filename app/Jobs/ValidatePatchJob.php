<?php

namespace App\Jobs;

use App\Agents\ValidationAgent;
use App\Enums\IncidentStatus;
use App\Jobs\Concerns\HandlesAgentTechnicalRetries;
use App\Jobs\Concerns\TracksAgentRuns;
use App\Models\Incident;
use App\Services\Incident\IncidentStateMachine;
use App\Workflows\IncidentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ValidatePatchJob implements ShouldQueue
{
    use Dispatchable, HandlesAgentTechnicalRetries, InteractsWithQueue, Queueable, SerializesModels, TracksAgentRuns;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Incident $incident,
    ) {
        $this->onQueue('incidents');
        $this->timeout = (int) config('patchops.timeouts.validate_job', 900);
    }

    /**
     * Execute the job.
     */
    public function handle(
        ValidationAgent $agent,
        IncidentOrchestrator $orchestrator,
        IncidentStateMachine $stateMachine,
    ): void {
        Log::withContext([
            'correlation_id' => $this->incident->correlation_id,
            'patch_attempt' => $this->incident->getPatchAttempts(),
        ]);

        $this->incident->refresh();

        if ($this->incident->status === IncidentStatus::PATCHING) {
            $stateMachine->transition(
                incident: $this->incident,
                targetStatus: IncidentStatus::VALIDATING,
                reason: 'Automated patch validation initiated',
                actorType: 'agent',
                actorId: 'validation-agent',
            );
        }

        $attempt = max(1, $this->incident->getPatchAttempts() + 1);
        $inputContext = [
            'incident_number' => $this->incident->incident_number,
            'attempt' => $attempt,
            'diff' => $this->incident->metadata['diff'] ?? null,
            'fix_summary' => $this->incident->metadata['fix_summary'] ?? null,
        ];

        $result = $this->trackAgentExecution(
            agentType: 'validation',
            attempt: $attempt,
            inputContext: $inputContext,
            execution: fn () => $agent->validate($this->incident)
        );

        $orchestrator->handleValidationResult($this->incident, $result);
    }

    /**
     * Handle technical retry exhaustion.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->handleTechnicalFailure($exception, 'ValidatePatchJob');
        }
    }
}
