<?php

namespace App\Services\MCP;

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Exceptions\MCP\HitlApprovalRequiredException;
use App\Exceptions\MCP\InvalidToolArgumentsException;
use App\Exceptions\MCP\RepositoryAccessDeniedException;
use App\Exceptions\MCP\ResourceAccessDeniedException;
use App\Exceptions\MCP\UnauthorizedCriticalActionException;
use App\Exceptions\MCP\UnauthorizedToolException;
use App\Exceptions\Sandbox\SandboxInfrastructureException;
use App\Models\Incident;
use App\Models\SandboxExecution;
use App\Models\ToolExecution;
use App\Services\AuditLogger;
use App\Services\MCP\DTOs\ToolErrorResponseDTO;
use App\Services\MCP\Guards\AgentExecutionBudgetGuard;
use App\Services\MCP\Guards\HitlApprovalGuard;
use App\Services\MCP\Guards\RepositoryAccessGuard;
use App\Services\MCP\Guards\SandboxExecutionGuard;
use App\Services\MCP\Guards\SandboxLifecycleGuard;
use App\Services\MCP\Guards\ToolPermissionGuard;
use App\Services\MCP\Guards\ToolRiskLevelGuard;
use App\Services\Sandbox\Guards\SandboxSecurityAuditGuard;
use App\Services\Tracing\TraceContext;
use App\Tools\Enums\AgentRole;
use App\Tools\Exceptions\ToolNotFoundException;
use App\Tools\Permissions\ToolScope;
use App\Tools\ToolRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MCPToolGateway
{
    public function __construct(
        protected ?MCPPermissionService $permissionService = null,
        protected ?ToolRegistry $registry = null,
        protected ?MCPClient $mcpClient = null,
        protected ?RepositoryAccessGuard $repositoryGuard = null,
        protected ?SandboxExecutionGuard $sandboxGuard = null,
        protected ?SandboxLifecycleGuard $sandboxLifecycleGuard = null,
        protected ?SandboxSecurityAuditGuard $sandboxAuditGuard = null,
        protected ?ToolRiskLevelGuard $riskLevelGuard = null,
        protected ?HitlApprovalGuard $hitlGuard = null,
        protected ?AgentExecutionBudgetGuard $budgetGuard = null,
    ) {
        $this->permissionService ??= app(MCPPermissionService::class);
        $this->registry ??= app(ToolRegistry::class);
        $this->mcpClient ??= app(MCPClient::class);
        $this->repositoryGuard ??= app(RepositoryAccessGuard::class);
        $this->sandboxGuard ??= app(SandboxExecutionGuard::class);
        $this->sandboxLifecycleGuard ??= app(SandboxLifecycleGuard::class);
        $this->sandboxAuditGuard ??= app(SandboxSecurityAuditGuard::class);
        $this->riskLevelGuard ??= app(ToolRiskLevelGuard::class);
        $this->hitlGuard ??= app(HitlApprovalGuard::class);
        $this->budgetGuard ??= app(AgentExecutionBudgetGuard::class);
    }

    /**
     * Resilient invocation method that executes a tool and catches any exception,
     * returning a standardized JSON error envelope to LLM callers without crashing the loop.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function invoke(
        AgentRole $role,
        string $toolName,
        array $arguments,
        Incident $context,
        ?int $agentRunId = null,
    ): array {
        try {
            return $this->execute(
                role: $role,
                toolName: $toolName,
                arguments: $arguments,
                context: $context,
                agentRunId: $agentRunId,
            );
        } catch (Throwable $e) {
            return $this->formatErrorResponse($e, $toolName, $context);
        }
    }

    /**
     * Execute a tool through the strict non-bypassable MCP Tool Gateway pipeline with repository & sandbox isolation,
     * HITL approval gates, budget enforcement, immutable tool execution logging, and zero-trust credential isolation.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws UnauthorizedToolException
     * @throws HitlApprovalRequiredException
     * @throws ForbiddenHostCapabilityException
     * @throws UnauthorizedCriticalActionException
     * @throws RepositoryAccessDeniedException
     * @throws ResourceAccessDeniedException
     * @throws InvalidToolArgumentsException
     * @throws ToolNotFoundException
     */
    public function execute(
        AgentRole $role,
        string $toolName,
        array $arguments,
        Incident $context,
        ?int $agentRunId = null,
    ): array {
        $startTime = microtime(true);
        $correlationId = $context->correlation_id ?: ('corr_'.Str::ulid());
        $sandboxId = $arguments['sandbox_id'] ?? $arguments['workspace_id'] ?? null;

        TraceContext::set(
            correlationId: $correlationId,
            incidentId: (string) $context->id,
            agentRunId: $agentRunId ? (string) $agentRunId : null,
            sandboxId: $sandboxId ? (string) $sandboxId : null,
            agentRole: $role->value,
        );

        Log::info("MCPToolGateway: Agent role [{$role->value}] executing tool [{$toolName}].", [
            'incident_id' => $context->id,
            'tool' => $toolName,
            'role' => $role->value,
            'agent_run_id' => $agentRunId,
            'correlation_id' => $correlationId,
        ]);

        $redactedArgs = $this->redactSensitiveValues($arguments);

        // 1. Tool Existence Check
        if (! $this->registry->has($toolName)) {
            throw new ToolNotFoundException($toolName);
        }

        $tool = $this->registry->get($toolName);
        $permission = $tool->requiredPermission()->value;
        $riskLevel = $tool->definition()->riskLevel->value;

        // 2. Open running record in tool_executions telemetry
        $executionRecord = null;
        try {
            $executionRecord = ToolExecution::create([
                'incident_id' => $context->id,
                'agent_run_id' => $agentRunId,
                'tool_name' => $toolName,
                'arguments' => $redactedArgs,
                'status' => 'running',
                'permission' => $permission,
                'risk_level' => $riskLevel,
                'correlation_id' => $correlationId,
                'started_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning("Could not persist initial tool_executions record: {$e->getMessage()}");
        }

        try {
            // 3. Capability & Role Permission Authorization
            ToolPermissionGuard::assertPermission($role, $toolName, $this->registry);

            if (! $this->permissionService->isAllowed($role, $toolName)) {
                $this->finalizeExecutionRecord($executionRecord, 'denied', [
                    'error' => "Agent role [{$role->value}] is not authorized for tool [{$toolName}].",
                ], $startTime);

                throw new UnauthorizedToolException($role, $toolName);
            }

            // 4. Operational Budget & Quota Enforcement (Tool count, execution timeout, sandbox instances)
            $this->budgetGuard->validatePreExecution($role, $tool, $context, $agentRunId);

            // 5. Strict Repository Boundary Isolation Guard
            $this->repositoryGuard->validate($context, $arguments, $toolName);

            // 6. Sandbox Security Boundary & Breakout Guard
            $this->sandboxGuard->validate($context, $arguments, $toolName);

            // 6.5. Sandbox Lifecycle State Machine & Opaque Identifier Guard
            $this->sandboxLifecycleGuard->validate($context, $arguments, $toolName);

            // 6.6. Docker Socket Anti-Escape & Network Isolation Audit Guard
            $this->sandboxAuditGuard->validate($context, $arguments, $toolName);

            // 7. Multi-Tier Tool Risk Level Evaluation
            $this->riskLevelGuard->evaluate($tool, $arguments, $context);

            // 8. Human-In-The-Loop (HITL) Execution Gate
            $this->hitlGuard->validate($tool, $arguments, $context);

            $scopeStr = $tool->requiredPermission()->value;
            $scopeEnum = ToolScope::tryFrom($scopeStr) ?? ToolScope::GITHUB_READ;

            // 9. ABAC Resource-Level Constraint Validation
            try {
                $this->permissionService->validateResourceConstraints($scopeEnum, $arguments, $context);
            } catch (ResourceAccessDeniedException $e) {
                AuditLogger::logSystemAction(
                    event: 'security.resource_access_denied',
                    auditable: $context,
                    payload: [
                        'tool' => $toolName,
                        'role' => $role->value,
                        'scope' => $scopeEnum->value,
                        'violating_resource' => $e->violatingResource,
                        'reason' => $e->reason,
                        'arguments' => $redactedArgs,
                    ],
                    correlationId: $correlationId,
                );

                Log::critical("Security violation on incident [{$context->incident_number}]: {$e->getMessage()}");

                $this->finalizeExecutionRecord($executionRecord, 'denied', [
                    'error' => $e->getMessage(),
                    'violating_resource' => $e->violatingResource,
                ], $startTime);

                throw $e;
            }

            // 10. Validate Input Arguments against Schema
            $this->validateArguments($toolName, $tool->parametersSchema(), $arguments);

            // 11. Monitored Tool Execution with Automatic Transient Retry Interceptor
            $maxAttempts = 3;
            $attempt = 1;
            $rawOutput = null;

            while ($attempt <= $maxAttempts) {
                try {
                    $rawOutput = $tool->execute($arguments, $context);
                    break;
                } catch (SandboxInfrastructureException|ConnectionException $e) {
                    if ($attempt >= $maxAttempts) {
                        throw $e;
                    }
                    Log::warning("MCPToolGateway: Transient error during [{$toolName}] (Attempt {$attempt}/{$maxAttempts}): {$e->getMessage()}. Retrying...");
                    usleep(50000 * $attempt); // Jittered backoff (50ms, 100ms)
                    $attempt++;
                }
            }

            // 12. Sanitize, Truncate, and Redact Secrets
            $sanitizedOutput = $this->sanitizeResponse($rawOutput ?? []);
            $budgetBoundedOutput = $this->budgetGuard->truncateOutput($sanitizedOutput, $role);

            $executionTime = round(microtime(true) - $startTime, 3);
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            // 12.5. Track Sandbox Execution Telemetry if command was run
            if (in_array($toolName, ['sandbox.execute', 'sandbox.execute_command'], true)) {
                try {
                    SandboxExecution::create([
                        'incident_id' => $context->id,
                        'sandbox_id' => (string) ($sandboxId ?? 'unknown'),
                        'agent_run_id' => $agentRunId ? (string) $agentRunId : null,
                        'correlation_id' => $correlationId,
                        'command' => (string) ($arguments['command'] ?? ''),
                        'exit_code' => (int) ($rawOutput['exit_code'] ?? 0),
                        'stdout' => (string) ($rawOutput['stdout'] ?? ''),
                        'stderr' => (string) ($rawOutput['stderr'] ?? ''),
                        'duration_ms' => (float) ($rawOutput['duration_ms'] ?? $durationMs),
                    ]);
                } catch (Throwable $e) {
                    Log::warning("Could not persist sandbox_executions record: {$e->getMessage()}");
                }
            }

            // 13. Finalize tool_executions record as SUCCESS
            $this->finalizeExecutionRecord($executionRecord, 'success', $budgetBoundedOutput, $startTime);

            // 14. Record Audit Event
            if (config('mcp.security.audit_invocations', true)) {
                AuditLogger::logSystemAction(
                    event: 'mcp_gateway.tool_executed',
                    auditable: $context,
                    payload: [
                        'tool' => $toolName,
                        'role' => $role->value,
                        'risk_level' => $riskLevel,
                        'arguments' => $redactedArgs,
                        'execution_time_seconds' => $executionTime,
                        'duration_ms' => $durationMs,
                    ],
                    correlationId: $correlationId,
                );
            }

            return [
                'success' => true,
                'tool' => $toolName,
                'role' => $role->value,
                'risk_level' => $riskLevel,
                'data' => $budgetBoundedOutput,
                'execution_time_seconds' => $executionTime,
                'duration_ms' => $durationMs,
            ];
        } catch (HitlApprovalRequiredException $e) {
            $this->finalizeExecutionRecord($executionRecord, 'pending_approval', [
                'reason' => $e->getMessage(),
                'required_action' => $e->requiredAction,
            ], $startTime);

            throw $e;
        } catch (ForbiddenHostCapabilityException|UnauthorizedCriticalActionException $e) {
            $this->finalizeExecutionRecord($executionRecord, 'denied', [
                'error' => $e->getMessage(),
            ], $startTime);

            throw $e;
        } catch (Throwable $e) {
            if ($executionRecord && $executionRecord->status === 'running') {
                $status = ($e instanceof UnauthorizedToolException || $e instanceof ResourceAccessDeniedException)
                    ? 'denied'
                    : 'failed';

                $this->finalizeExecutionRecord($executionRecord, $status, [
                    'error' => $e->getMessage(),
                    'exception' => class_basename($e),
                ], $startTime);
            }

            throw $e;
        }
    }

    /**
     * Format a standard structured error envelope for agent consumption.
     *
     * @return array{success: false, error: array{code: string, message: string, retryable: bool, details: array<string, mixed>}}
     */
    public function formatErrorResponse(Throwable $e, string $toolName, Incident $context): array
    {
        return ToolErrorResponseDTO::fromException($e, [
            'tool_name' => $toolName,
            'incident_number' => $context->incident_number,
        ])->toArray();
    }

    /**
     * Finalize the ToolExecution database record.
     *
     * @param  array<string, mixed>  $resultPayload
     */
    protected function finalizeExecutionRecord(?ToolExecution $record, string $status, array $resultPayload, float $startTime): void
    {
        if (! $record) {
            return;
        }

        try {
            $durationMs = round((microtime(true) - $startTime) * 1000, 2);
            $record->update([
                'status' => $status,
                'result' => $resultPayload,
                'completed_at' => now(),
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            Log::warning("Failed to update tool_executions record [{$record->id}]: {$e->getMessage()}");
        }
    }

    /**
     * Validate arguments against parameter schema.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $arguments
     *
     * @throws InvalidToolArgumentsException
     */
    protected function validateArguments(string $toolName, array $schema, array $arguments): void
    {
        $requiredFields = $schema['required'] ?? [];

        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $arguments) || $arguments[$field] === null || $arguments[$field] === '') {
                throw new InvalidToolArgumentsException(
                    toolName: $toolName,
                    message: "Required parameter [{$field}] is missing or empty.",
                );
            }
        }
    }

    /**
     * Sanitize and truncate oversized response fields.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    protected function sanitizeResponse(array $output): array
    {
        $maxChars = (int) config('mcp.security.max_output_characters', 10000);

        return array_map(function ($value) use ($maxChars) {
            if (is_string($value)) {
                $sanitized = $this->redactSecretsInString($value);

                if (strlen($sanitized) > $maxChars) {
                    return substr($sanitized, 0, $maxChars)."\n...[OUTPUT TRUNCATED BY MCP TOOL GATEWAY]";
                }

                return $sanitized;
            }

            return $value;
        }, $output);
    }

    /**
     * Redact API tokens and secret strings from logs or output.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function redactSensitiveValues(array $data): array
    {
        $sensitiveKeys = ['token', 'password', 'secret', 'key', 'auth', 'pat'];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $lowerKey = strtolower($key);
                foreach ($sensitiveKeys as $sensitive) {
                    if (str_contains($lowerKey, $sensitive)) {
                        $data[$key] = '***REDACTED***';
                        break;
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Redact common tokens inside raw text strings.
     */
    protected function redactSecretsInString(string $text): string
    {
        // Redact GitHub PATs (ghp_..., github_pat_...) and Bearer tokens
        $text = preg_replace('/(ghp_[A-Za-z0-9_]{36}|github_pat_[A-Za-z0-9_]{82})/', '***REDACTED_GITHUB_TOKEN***', $text);
        $text = preg_replace('/(Bearer\s+[A-Za-z0-9\-._~+\/]+=*)/i', 'Bearer ***REDACTED***', $text);

        return $text;
    }
}
