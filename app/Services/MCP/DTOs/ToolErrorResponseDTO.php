<?php

namespace App\Services\MCP\DTOs;

use App\Exceptions\MCP\ForbiddenHostCapabilityException;
use App\Exceptions\MCP\HitlApprovalRequiredException;
use App\Exceptions\MCP\InvalidToolArgumentsException;
use App\Exceptions\MCP\RepositoryAccessDeniedException;
use App\Exceptions\MCP\ResourceAccessDeniedException;
use App\Exceptions\MCP\UnauthorizedCriticalActionException;
use App\Exceptions\MCP\UnauthorizedToolException;
use App\Tools\Exceptions\InvalidToolArgumentException;
use App\Tools\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use JsonSerializable;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

readonly class ToolErrorResponseDTO implements JsonSerializable
{
    public const PERMISSION_DENIED = 'PERMISSION_DENIED';

    public const RESOURCE_OUT_OF_SCOPE = 'RESOURCE_OUT_OF_SCOPE';

    public const INVALID_ARGUMENTS = 'INVALID_ARGUMENTS';

    public const TOOL_TIMEOUT = 'TOOL_TIMEOUT';

    public const DOWNSTREAM_UNAVAILABLE = 'DOWNSTREAM_UNAVAILABLE';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const SANDBOX_QUOTA_EXCEEDED = 'SANDBOX_QUOTA_EXCEEDED';

    public const BUDGET_EXCEEDED_TOOL_CALLS = 'BUDGET_EXCEEDED_TOOL_CALLS';

    public const BUDGET_EXCEEDED_TIMEOUT = 'BUDGET_EXCEEDED_TIMEOUT';

    public const HITL_APPROVAL_REQUIRED = 'HITL_APPROVAL_REQUIRED';

    public const OUTPUT_TRUNCATED = 'OUTPUT_TRUNCATED';

    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $code,
        public string $message,
        public bool $retryable = false,
        public array $details = [],
    ) {}

    /**
     * Factory to normalize any Throwable exception into a structured ToolErrorResponseDTO.
     *
     * @param  array<string, mixed>  $extraDetails
     */
    public static function fromException(Throwable $e, array $extraDetails = []): self
    {
        $details = array_merge([
            'exception_class' => class_basename($e),
        ], $extraDetails);

        return match (true) {
            $e instanceof UnauthorizedToolException,
            $e instanceof ForbiddenHostCapabilityException,
            $e instanceof UnauthorizedCriticalActionException => new self(
                code: self::PERMISSION_DENIED,
                message: $e->getMessage(),
                retryable: false,
                details: $details,
            ),

            $e instanceof HitlApprovalRequiredException => new self(
                code: self::HITL_APPROVAL_REQUIRED,
                message: $e->getMessage(),
                retryable: false,
                details: array_merge($details, ['required_action' => $e->requiredAction]),
            ),

            $e instanceof RepositoryAccessDeniedException,
            $e instanceof ResourceAccessDeniedException => new self(
                code: self::RESOURCE_OUT_OF_SCOPE,
                message: $e->getMessage(),
                retryable: false,
                details: array_merge($details, [
                    'violating_resource' => method_exists($e, 'getViolatingResource') ? $e->violatingResource : ($e->violatingResource ?? null),
                ]),
            ),

            $e instanceof InvalidToolArgumentsException,
            $e instanceof InvalidToolArgumentException => new self(
                code: self::INVALID_ARGUMENTS,
                message: $e->getMessage(),
                retryable: false,
                details: array_merge($details, ['tool_name' => $e->toolName ?? null]),
            ),

            $e instanceof ValidationException => new self(
                code: self::INVALID_ARGUMENTS,
                message: $e->getMessage(),
                retryable: false,
                details: array_merge($details, ['errors' => $e->errors()]),
            ),

            $e instanceof ToolNotFoundException => new self(
                code: self::INVALID_ARGUMENTS,
                message: $e->getMessage(),
                retryable: false,
                details: $details,
            ),

            $e instanceof ProcessTimedOutException => new self(
                code: self::TOOL_TIMEOUT,
                message: "Tool operation timed out: {$e->getMessage()}",
                retryable: true,
                details: $details,
            ),

            $e instanceof ConnectionException => new self(
                code: self::DOWNSTREAM_UNAVAILABLE,
                message: "Downstream service unavailable: {$e->getMessage()}",
                retryable: true,
                details: $details,
            ),

            str_contains(strtolower($e->getMessage()), 'sandbox quota') => new self(
                code: self::SANDBOX_QUOTA_EXCEEDED,
                message: $e->getMessage(),
                retryable: false,
                details: $details,
            ),

            str_contains(strtolower($e->getMessage()), 'tool call budget') || str_contains(strtolower($e->getMessage()), 'max tool calls') => new self(
                code: self::BUDGET_EXCEEDED_TOOL_CALLS,
                message: $e->getMessage(),
                retryable: false,
                details: $details,
            ),

            str_contains(strtolower($e->getMessage()), 'execution timeout') || str_contains(strtolower($e->getMessage()), 'duration budget') => new self(
                code: self::BUDGET_EXCEEDED_TIMEOUT,
                message: $e->getMessage(),
                retryable: false,
                details: $details,
            ),

            default => new self(
                code: self::INTERNAL_ERROR,
                message: $e->getMessage() ?: 'An unexpected error occurred during tool execution.',
                retryable: false,
                details: $details,
            ),
        };
    }

    /**
     * Convert the DTO to standard JSON-compatible array structure.
     *
     * @return array{success: false, error: array{code: string, message: string, retryable: bool, details: array<string, mixed>}}
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => $this->code,
                'message' => $this->message,
                'retryable' => $this->retryable,
                'details' => $this->details,
            ],
        ];
    }

    /**
     * JSON serialization implementation.
     *
     * @return array{success: false, error: array{code: string, message: string, retryable: bool, details: array<string, mixed>}}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
