<?php

namespace App\Services\MCP\Guards;

use App\Exceptions\MCP\ExecutionTimeoutExceededException;
use App\Exceptions\MCP\SandboxQuotaExceededException;
use App\Exceptions\MCP\ToolCallBudgetExceededException;
use App\Exceptions\MCP\UnauthorizedToolException;
use App\Models\AgentRun;
use App\Models\Incident;
use App\Models\ToolExecution;
use App\Tools\Contracts\ToolInterface;
use App\Tools\Enums\AgentRole;
use App\Tools\Enums\ToolPermission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AgentExecutionBudgetGuard
{
    /**
     * Validate operational quotas, execution timeout, sandbox quotas, and write constraints before tool execution.
     *
     * @throws ToolCallBudgetExceededException
     * @throws ExecutionTimeoutExceededException
     * @throws SandboxQuotaExceededException
     * @throws UnauthorizedToolException
     */
    public function validatePreExecution(
        AgentRole $role,
        ToolInterface $tool,
        Incident $incident,
        ?int $agentRunId = null,
    ): void {
        $toolName = $tool->name();
        $budget = (array) config("agent_budgets.{$role->value}", []);

        // 1. Tool Call Counter Budget
        $maxCalls = (int) ($budget['max_tool_calls'] ?? 20);
        if ($agentRunId) {
            $currentCalls = ToolExecution::where('agent_run_id', $agentRunId)->count();
            if ($currentCalls >= $maxCalls) {
                Log::warning("Agent role [{$role->value}] exceeded tool call budget of {$maxCalls} on incident [{$incident->incident_number}].");
                throw new ToolCallBudgetExceededException($role, $currentCalls + 1, $maxCalls);
            }
        }

        // 2. Wall-Clock Execution Timeout Budget
        $maxTimeout = (int) ($budget['max_execution_seconds'] ?? 600);
        if ($agentRunId) {
            $agentRun = AgentRun::find($agentRunId);
            if ($agentRun && $agentRun->started_at) {
                $startedAt = $agentRun->started_at instanceof Carbon
                    ? $agentRun->started_at
                    : Carbon::parse($agentRun->started_at);
                $elapsed = (int) $startedAt->diffInSeconds(now());
                if ($elapsed >= $maxTimeout) {
                    Log::warning("Agent role [{$role->value}] exceeded execution timeout ({$elapsed}s/{$maxTimeout}s) on incident [{$incident->incident_number}].");
                    throw new ExecutionTimeoutExceededException($role, $elapsed, $maxTimeout);
                }
            }
        }

        // 3. Sandbox Instance Quota Tracker
        if ($toolName === 'sandbox.create_environment' || str_starts_with($toolName, 'sandbox.create')) {
            $allowSandbox = (bool) ($budget['allow_sandbox'] ?? false);
            $maxSandboxes = (int) ($budget['max_sandboxes'] ?? 0);

            if (! $allowSandbox || $maxSandboxes <= 0) {
                throw new SandboxQuotaExceededException($role, 0, 0);
            }

            $activeSandboxes = ToolExecution::where('incident_id', $incident->id)
                ->where('tool_name', 'sandbox.create_environment')
                ->where('status', 'success')
                ->count();

            if ($activeSandboxes >= $maxSandboxes) {
                Log::warning("Agent role [{$role->value}] exceeded sandbox instance quota ({$activeSandboxes}/{$maxSandboxes}) on incident [{$incident->incident_number}].");
                throw new SandboxQuotaExceededException($role, $activeSandboxes, $maxSandboxes);
            }
        }

        // 4. Role-Level Write Mutation Block
        $allowWrite = (bool) ($budget['allow_write'] ?? false);
        $requiredPermission = $tool->requiredPermission();
        if (! $allowWrite && in_array($requiredPermission, [ToolPermission::GITHUB_WRITE, ToolPermission::REPOSITORY_WRITE], true)) {
            Log::warning("Agent role [{$role->value}] attempted write tool [{$toolName}] but write actions are disabled for role.");
            throw new UnauthorizedToolException($role, $toolName);
        }
    }

    /**
     * Enforce response size caps and truncate oversized tool outputs to protect LLM context windows.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public function truncateOutput(array $output, AgentRole $role): array
    {
        $budget = (array) config("agent_budgets.{$role->value}", []);
        $maxBytes = (int) ($budget['max_response_bytes'] ?? (100 * 1024));

        $jsonEncoded = json_encode($output, JSON_UNESCAPED_SLASHES);
        if ($jsonEncoded === false || strlen($jsonEncoded) <= $maxBytes) {
            return $output;
        }

        // Output exceeds byte limit -> Truncate string fields gracefully
        return array_map(function ($value) use ($maxBytes) {
            if (is_string($value) && strlen($value) > ($maxBytes / 2)) {
                $allowedLength = (int) ($maxBytes / 2);

                return substr($value, 0, $allowedLength)."\n\n...[OUTPUT TRUNCATED BY MCP BUDGET GUARD: MAX RESPONSE SIZE EXCEEDED]";
            }

            return $value;
        }, $output);
    }
}
