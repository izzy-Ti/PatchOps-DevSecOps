<?php

namespace App\Jobs\Concerns;

use App\Enums\IncidentStatus;
use App\Services\Incident\IncidentStateMachine;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

trait HandlesAgentTechnicalRetries
{
    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     * Uses exponential backoff (5s, 20s, 60s).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [5, 20, 60];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    /**
     * Handle a job failure when technical retries are completely exhausted.
     */
    public function handleTechnicalFailure(Throwable $exception, string $agentName): void
    {
        $attempts = method_exists($this, 'attempts') ? $this->attempts() : $this->tries;

        Log::error("Job [{$agentName}] failed after exhausting technical retries: {$exception->getMessage()}", [
            'correlation_id' => $this->incident->correlation_id,
            'incident_id' => $this->incident->id,
            'attempts' => $attempts,
            'exception' => $exception->getTraceAsString(),
        ]);

        app(IncidentStateMachine::class)->transition(
            incident: $this->incident,
            targetStatus: IncidentStatus::ESCALATED,
            reason: "Technical infrastructure failure in {$agentName}: {$exception->getMessage()}",
            actorType: 'system',
            actorId: 'queue-worker',
            metadata: [
                'error' => $exception->getMessage(),
                'attempts' => $attempts,
            ],
        );
    }
}
