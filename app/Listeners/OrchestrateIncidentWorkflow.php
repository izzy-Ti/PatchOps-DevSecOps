<?php

namespace App\Listeners;

use App\Events\IncidentStatusChanged;
use App\Workflows\IncidentOrchestrator;

class OrchestrateIncidentWorkflow
{
    /**
     * Create the event listener.
     */
    public function __construct(
        protected IncidentOrchestrator $orchestrator,
    ) {}

    /**
     * Handle the incident status changed event.
     */
    public function handle(IncidentStatusChanged $event): void
    {
        $this->orchestrator->handle($event->incident);
    }
}
