<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Services\Orchestration\IncidentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteReproductionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Incident $incident,
    ) {
        $this->onQueue('incidents');
    }

    public function handle(IncidentOrchestrator $orchestrator): void
    {
        $orchestrator->handlePrioritized($this->incident);
    }
}
