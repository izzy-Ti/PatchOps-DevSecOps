<?php

namespace App\Jobs;

use App\Agents\TriageAgent;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Services\Incident\IncidentStateMachine;
use App\Workflows\IncidentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TriageIncidentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Incident $incident,
    ) {
        $this->onQueue('incidents');
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

        $result = $agent->analyze($this->incident);

        $orchestrator->handleTriageResult($this->incident, $result);
    }
}
