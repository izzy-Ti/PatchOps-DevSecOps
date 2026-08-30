<?php

namespace App\Jobs\Concerns;

use App\Enums\IncidentStatus;
use App\Exceptions\SandboxTimeoutException;
use App\Services\Incident\IncidentStateMachine;
use DateTimeInterface;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
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
     * Handle a job failure when technical retries are completely exhausted or timed out.
     */
    public function handleTechnicalFailure(Throwable $exception, string $agentName): void
    {
        $attempts = method_exists($this, 'attempts') ? $this->attempts() : $this->tries;
        $timeoutLimit = property_exists($this, 'timeout') ? $this->timeout : 120;
        $isTimeout = $this->isTimeoutException($exception);

        if ($isTimeout) {
            Log::error("Job [{$agentName}] timed out after {$timeoutLimit}s: {$exception->getMessage()}", [
                'correlation_id' => $this->incident->correlation_id,
                'incident_id' => $this->incident->id,
                'timeout_limit' => $timeoutLimit,
                'attempts' => $attempts,
            ]);

            app(IncidentStateMachine::class)->transition(
                incident: $this->incident,
                targetStatus: IncidentStatus::ESCALATED,
                reason: "Execution timed out in {$agentName} after {$timeoutLimit}s limit.",
                actorType: 'system',
                actorId: 'timeout-handler',
                metadata: [
                    'timeout_limit' => $timeoutLimit,
                    'error' => $exception->getMessage(),
                    'attempts' => $attempts,
                ],
            );

            return;
        }

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

    /**
     * Determine whether an exception indicates an execution timeout.
     */
    protected function isTimeoutException(Throwable $exception): bool
    {
        if (
            $exception instanceof SandboxTimeoutException ||
            $exception instanceof ProcessTimedOutException ||
            $exception instanceof TimeoutExceededException ||
            $exception instanceof MaxAttemptsExceededException
        ) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'curl error 28');
    }
}
