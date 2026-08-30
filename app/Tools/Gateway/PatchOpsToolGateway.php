<?php

namespace App\Tools\Gateway;

use App\Models\Incident;
use App\Services\AuditLogger;
use App\Tools\Enums\AgentRole;
use App\Tools\Exceptions\InvalidToolArgumentException;
use App\Tools\Exceptions\ToolNotFoundException;
use App\Tools\Exceptions\UnauthorizedToolException;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

class PatchOpsToolGateway
{
    public function __construct(
        protected ?ToolRegistry $registry = null,
    ) {
        $this->registry ??= app(ToolRegistry::class);
    }

    /**
     * Intercept, authorize, execute, and sanitize a tool invocation requested by an agent.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{success: bool, is_error: bool, data?: array<string, mixed>, error?: string, execution_time_seconds: float}
     */
    public function invokeTool(
        string $toolName,
        array $arguments,
        AgentRole $role,
        Incident $incident,
    ): array {
        $startTime = microtime(true);

        Log::info("Tool Gateway: Agent role [{$role->value}] requesting tool [{$toolName}].", [
            'incident_id' => $incident->id,
            'tool' => $toolName,
            'role' => $role->value,
        ]);

        try {
            // 1. Check Tool Existence
            if (! $this->registry->has($toolName)) {
                throw new ToolNotFoundException($toolName);
            }

            // 2. Authorize Agent Role Permissions
            if (! $this->registry->authorize($toolName, $role)) {
                $tool = $this->registry->get($toolName);
                throw new UnauthorizedToolException($toolName, $role, $tool->requiredPermission());
            }

            // 3. Execute Tool via Registry
            $rawOutput = $this->registry->execute($toolName, $arguments, $role, $incident);

            // 4. Sanitize and Truncate Large Outputs
            $sanitizedOutput = $this->sanitizeOutput($rawOutput);
            $executionTime = round(microtime(true) - $startTime, 3);

            // 5. Record Audit Trail
            if (config('mcp.security.audit_invocations', true)) {
                AuditLogger::logSystemAction(
                    event: 'tool_gateway.invocation',
                    auditable: $incident,
                    payload: [
                        'tool' => $toolName,
                        'role' => $role->value,
                        'arguments' => $arguments,
                        'execution_time_seconds' => $executionTime,
                    ],
                    correlationId: $incident->correlation_id,
                );
            }

            return [
                'success' => true,
                'is_error' => false,
                'data' => $sanitizedOutput,
                'execution_time_seconds' => $executionTime,
            ];
        } catch (UnauthorizedToolException|ToolNotFoundException|InvalidToolArgumentException $e) {
            $executionTime = round(microtime(true) - $startTime, 3);

            Log::warning("Tool Gateway authorization/validation rejection on [{$toolName}]: {$e->getMessage()}", [
                'incident_id' => $incident->id,
                'role' => $role->value,
            ]);

            return [
                'success' => false,
                'is_error' => true,
                'error' => "Gateway Error: {$e->getMessage()}",
                'execution_time_seconds' => $executionTime,
            ];
        } catch (Throwable $e) {
            $executionTime = round(microtime(true) - $startTime, 3);

            Log::error("Tool Gateway execution exception on [{$toolName}]: {$e->getMessage()}", [
                'incident_id' => $incident->id,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'is_error' => true,
                'error' => "External MCP/Tool Execution Failed: {$e->getMessage()}",
                'execution_time_seconds' => $executionTime,
            ];
        }
    }

    /**
     * Sanitize and truncate large payload outputs to avoid context window blowup.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    protected function sanitizeOutput(array $output): array
    {
        $maxChars = config('mcp.security.max_output_characters', 10000);

        return array_map(function ($value) use ($maxChars) {
            if (is_string($value) && strlen($value) > $maxChars) {
                return substr($value, 0, $maxChars)."\n...[OUTPUT TRUNCATED BY TOOL GATEWAY]";
            }

            return $value;
        }, $output);
    }
}
