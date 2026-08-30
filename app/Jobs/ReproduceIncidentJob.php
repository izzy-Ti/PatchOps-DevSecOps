<?php

namespace App\Jobs;

use App\Agents\ReproductionAgent;
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

class ReproduceIncidentJob implements ShouldQueue
{
    use Dispatchable, HandlesAgentTechnicalRetries, InteractsWithQueue, Queueable, SerializesModels, TracksAgentRuns;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Incident $incident,
    ) {
        $this->onQueue('incidents');
        $this->timeout = (int) config('patchops.timeouts.reproduce_job', 600);
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
        ReproductionAgent $agent,
        IncidentOrchestrator $orchestrator,
        IncidentStateMachine $stateMachine,
    ): void {
        Log::withContext(['correlation_id' => $this->incident->correlation_id]);

        $this->incident->refresh();

        if ($this->incident->status === IncidentStatus::PRIORITIZED) {
            $stateMachine->transition(
                incident: $this->incident,
                targetStatus: IncidentStatus::REPRODUCING,
                reason: 'Automated vulnerability reproduction initiated',
                actorType: 'agent',
                actorId: 'reproduction-agent',
            );
        }

        $inputContext = [
            'incident_number' => $this->incident->incident_number,
            'title' => $this->incident->title,
            'repository' => $this->incident->repository,
            'metadata' => $this->incident->metadata,
        ];

        $result = $this->trackAgentExecution(
            agentType: 'reproduction',
            attempt: 1,
            inputContext: $inputContext,
            execution: fn () => $agent->reproduce($this->incident)
        );

        $orchestrator->handleReproductionResult($this->incident, $result);
    }

    /**
     * Handle technical retry exhaustion.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->handleTechnicalFailure($exception, 'ReproduceIncidentJob');
        }
    }
}
