<?php

namespace App\Services\MCP;

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Exceptions\MCP\InvalidToolArgumentsException;
use App\Exceptions\MCP\RepositoryAccessDeniedException;
use App\Exceptions\MCP\ResourceAccessDeniedException;
use App\Exceptions\MCP\UnauthorizedCriticalActionException;
use App\Exceptions\MCP\UnauthorizedToolException;
use App\Models\Incident;
use App\Services\AuditLogger;
use App\Services\MCP\Guards\RepositoryAccessGuard;
use App\Services\MCP\Guards\SandboxExecutionGuard;
use App\Services\MCP\Guards\ToolRiskLevelGuard;
use App\Tools\Enums\AgentRole;
use App\Tools\Exceptions\ToolNotFoundException;
use App\Tools\Permissions\ToolScope;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Log;

class MCPToolGateway
{
    public function __construct(
        protected ?MCPPermissionService $permissionService = null,
        protected ?ToolRegistry $registry = null,
        protected ?MCPClient $mcpClient = null,
        protected ?RepositoryAccessGuard $repositoryGuard = null,
        protected ?SandboxExecutionGuard $sandboxGuard = null,
        protected ?ToolRiskLevelGuard $riskLevelGuard = null,
    ) {
        $this->permissionService ??= app(MCPPermissionService::class);
        $this->registry ??= app(ToolRegistry::class);
        $this->mcpClient ??= app(MCPClient::class);
        $this->repositoryGuard ??= app(RepositoryAccessGuard::class);
        $this->sandboxGuard ??= app(SandboxExecutionGuard::class);
        $this->riskLevelGuard ??= app(ToolRiskLevelGuard::class);
    }

    /**
     * Execute a tool through the strict non-bypassable MCP Tool Gateway pipeline with repository & sandbox isolation.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws UnauthorizedToolException
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
    ): array {
        $startTime = microtime(true);

        Log::info("MCPToolGateway: Agent role [{$role->value}] executing tool [{$toolName}].", [
            'incident_id' => $context->id,
            'tool' => $toolName,
            'role' => $role->value,
        ]);

        // 1. Tool Existence Check
        if (! $this->registry->has($toolName)) {
            throw new ToolNotFoundException($toolName);
        }

        // 2. Capability & Role Permission Authorization
        if (! $this->permissionService->isAllowed($role, $toolName)) {
            throw new UnauthorizedToolException($role, $toolName);
        }

        // 3. Strict Repository Boundary Isolation Guard
        $this->repositoryGuard->validate($context, $arguments, $toolName);

        // 4. Sandbox Security Boundary & Breakout Guard
        $this->sandboxGuard->validate($context, $arguments, $toolName);

        $tool = $this->registry->get($toolName);

        // 5. Multi-Tier Tool Risk Level & HITL Gate Evaluation
        $this->riskLevelGuard->evaluate($tool, $arguments, $context);

        $scopeStr = $tool->requiredPermission()->value;
        $scopeEnum = ToolScope::tryFrom($scopeStr) ?? ToolScope::GITHUB_READ;

        // 6. ABAC Resource-Level Constraint Validation
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
                    'arguments' => $this->redactSensitiveValues($arguments),
                ],
                correlationId: $context->correlation_id,
            );

            Log::critical("Security violation on incident [{$context->incident_number}]: {$e->getMessage()}");

            throw $e;
        }

        // 7. Validate Input Arguments against Schema
        $this->validateArguments($toolName, $tool->parametersSchema(), $arguments);

        // 8. Monitored Tool Execution
        $rawOutput = $tool->execute($arguments, $context);

        // 9. Sanitize, Truncate, and Redact Secrets
        $sanitizedOutput = $this->sanitizeResponse($rawOutput);
        $executionTime = round(microtime(true) - $startTime, 3);

        // 10. Record Audit Event
        if (config('mcp.security.audit_invocations', true)) {
            AuditLogger::logSystemAction(
                event: 'mcp_gateway.tool_executed',
                auditable: $context,
                payload: [
                    'tool' => $toolName,
                    'role' => $role->value,
                    'risk_level' => $tool->definition()->riskLevel->value,
                    'arguments' => $this->redactSensitiveValues($arguments),
                    'execution_time_seconds' => $executionTime,
                ],
                correlationId: $context->correlation_id,
            );
        }

        return [
            'success' => true,
            'tool' => $toolName,
            'role' => $role->value,
            'risk_level' => $tool->definition()->riskLevel->value,
            'data' => $sanitizedOutput,
            'execution_time_seconds' => $executionTime,
        ];
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
        $sensitiveKeys = ['token', 'password', 'secret', 'key', 'auth'];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                $data[$key] = '***REDACTED***';
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
