<?php

namespace App\Services;

use App\DTOs\AgentResultDTO;
use App\Models\AgentRun;
use App\Models\Incident;
use Throwable;

class AgentRunTracker
{
    /**
     * Start a new agent execution run.
     *
     * @param  array<string, mixed>|null  $inputContext
     */
    public function start(
        Incident $incident,
        string $agentType,
        int $attempt = 1,
        ?array $inputContext = null,
    ): AgentRun {
        return AgentRun::create([
            'incident_id' => $incident->id,
            'agent_type' => $agentType,
            'status' => 'running',
            'attempt' => $attempt,
            'input_context' => $inputContext,
            'started_at' => now(),
            'correlation_id' => $incident->correlation_id,
        ]);
    }

    /**
     * Complete an agent run upon successful execution.
     */
    public function complete(AgentRun $run, AgentResultDTO $result): void
    {
        $now = now();
        $startedAt = $run->started_at ?? $now;
        $duration = round(abs($startedAt->diffInMilliseconds($now)) / 1000, 3);

        $run->update([
            'status' => 'completed',
            'output' => $result->data,
            'completed_at' => $now,
            'duration' => $duration,
        ]);
    }

    /**
     * Record failure on an agent run.
     */
    public function fail(AgentRun $run, AgentResultDTO|Throwable $error): void
    {
        $now = now();
        $startedAt = $run->started_at ?? $now;
        $duration = round(abs($startedAt->diffInMilliseconds($now)) / 1000, 3);

        $errorPayload = match (true) {
            $error instanceof AgentResultDTO => $error->error?->toArray() ?? [
                'code' => 'AGENT_FAILED',
                'message' => 'Agent returned failure status without detailed error',
            ],
            $error instanceof Throwable => [
                'code' => class_basename($error),
                'message' => $error->getMessage(),
                'trace' => $error->getTraceAsString(),
            ],
        };

        $run->update([
            'status' => 'failed',
            'error' => $errorPayload,
            'completed_at' => $now,
            'duration' => $duration,
        ]);
    }
}
