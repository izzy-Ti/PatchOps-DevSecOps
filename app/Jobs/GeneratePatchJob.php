<?php

namespace App\Jobs;

use App\Agents\PatchAgent;
use App\Enums\IncidentStatus;
use App\Jobs\Concerns\HandlesAgentTechnicalRetries;
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
    use Dispatchable, HandlesAgentTechnicalRetries, InteractsWithQueue, Queueable, SerializesModels;

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

        $result = $agent->generatePatch($this->incident);

        $orchestrator->handlePatchResult($this->incident, $result);
    }

    /**
     * Handle technical retry exhaustion.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->handleTechnicalFailure($exception, 'PatchAgent');
        }
    }
}
