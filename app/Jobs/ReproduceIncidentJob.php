<?php

namespace App\Jobs;

use App\Agents\ReproductionAgent;
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

class ReproduceIncidentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;

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

        $result = $agent->reproduce($this->incident);

        $orchestrator->handleReproductionResult($this->incident, $result);
    }
}
