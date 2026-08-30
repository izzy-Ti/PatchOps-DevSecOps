<?php

namespace App\Jobs;

use App\Agents\PatchAgent;
use App\Enums\IncidentStatus;
use App\Jobs\Concerns\HandlesAgentTechnicalRetries;
use App\Jobs\Concerns\TracksAgentRuns;
use App\Jobs\Middleware\LockIncidentExecution;
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

class GeneratePatchJob implements ShouldQueue
{
    use Dispatchable, HandlesAgentTechnicalRetries, InteractsWithQueue, Queueable, SerializesModels, TracksAgentRuns;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Incident $incident,
    ) {
        $this->onQueue('incidents');
        $this->timeout = (int) config('patchops.timeouts.patch_job', 300);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new LockIncidentExecution];
    }

    /**
     * Execute the job.
     */
    public function handle(
        PatchAgent $agent,
        IncidentOrchestrator $orchestrator,
        IncidentStateMachine $stateMachine,
    ): void {
        Log::withContext([
            'correlation_id' => $this->incident->correlation_id,
            'patch_attempt' => $this->incident->getPatchAttempts(),
        ]);

        $this->incident->refresh();

        if ($this->incident->status === IncidentStatus::REPRODUCED) {
            $stateMachine->transition(
                incident: $this->incident,
                targetStatus: IncidentStatus::PATCHING,
                reason: 'Automated patch synthesis initiated',
                actorType: 'agent',
                actorId: 'patch-agent',
            );
        }

        $attempt = max(1, $this->incident->getPatchAttempts() + 1);
        $inputContext = [
            'incident_number' => $this->incident->incident_number,
            'attempt' => $attempt,
            'last_validation_feedback' => $this->incident->getLatestValidationFeedback(),
            'metadata' => $this->incident->metadata,
        ];

        $result = $this->trackAgentExecution(
            agentType: 'patch',
            attempt: $attempt,
            inputContext: $inputContext,
            execution: fn () => $agent->generatePatch($this->incident)
        );

        $orchestrator->handlePatchResult($this->incident, $result);
    }

    /**
     * Handle technical retry exhaustion.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->handleTechnicalFailure($exception, 'GeneratePatchJob');
        }
    }
}
