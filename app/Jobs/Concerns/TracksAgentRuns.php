<?php

namespace App\Jobs\Concerns;

use App\DTOs\AgentResultDTO;
use App\Services\AgentRunTracker;
use Closure;
use Throwable;

trait TracksAgentRuns
{
    /**
     * Track the execution lifecycle of an agent within a queue job.
     *
     * @param  array<string, mixed>|null  $inputContext
     */
    protected function trackAgentExecution(
        string $agentType,
        int $attempt,
        ?array $inputContext,
        Closure $execution,
    ): AgentResultDTO {
        $tracker = app(AgentRunTracker::class);
        $run = $tracker->start($this->incident, $agentType, $attempt, $inputContext);

        try {
            /** @var AgentResultDTO $result */
            $result = $execution($run);

            if ($result->success) {
                $tracker->complete($run, $result);
            } else {
                $tracker->fail($run, $result);
            }

            return $result;
        } catch (Throwable $e) {
            $tracker->fail($run, $e);

            throw $e;
        }
    }
}
