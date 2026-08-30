<?php

namespace App\Jobs;

use App\Agents\TriageAgent;
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

class TriageIncidentJob implements ShouldQueue
{
    use Dispatchable, HandlesAgentTechnicalRetries, InteractsWithQueue, Queueable, SerializesModels, TracksAgentRuns;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Incident $incident,
    ) {
        $this->onQueue('incidents');
        $this->timeout = (int) config('patchops.timeouts.triage_job', 120);
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
        TriageAgent $agent,
        IncidentOrchestrator $orchestrator,
        IncidentStateMachine $stateMachine,
    ): void {
        Log::withContext(['correlation_id' => $this->incident->correlation_id]);

        $this->incident->refresh();

        if ($this->incident->status === IncidentStatus::RECEIVED) {
            $stateMachine->transition(
                incident: $this->incident,
                targetStatus: IncidentStatus::TRIAGING,
                reason: 'Automated triage worker initiated',
                actorType: 'agent',
                actorId: 'triage-agent',
            );
        }

        $inputContext = [
            'incident_number' => $this->incident->incident_number,
            'title' => $this->incident->title,
            'repository' => $this->incident->repository,
            'vulnerability_id' => $this->incident->vulnerability_id,
        ];

        $result = $this->trackAgentExecution(
            agentType: 'triage',
            attempt: 1,
            inputContext: $inputContext,
            execution: fn () => $agent->analyze($this->incident)
        );

        $orchestrator->handleTriageResult($this->incident, $result);
    }

    /**
     * Handle technical retry exhaustion.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->handleTechnicalFailure($exception, 'TriageIncidentJob');
        }
    }
}
