<?php

namespace App\DTOs;

readonly class AgentErrorDTO
{
    public const SCHEMA_VALIDATION_FAILED = 'SCHEMA_VALIDATION_FAILED';

    public const LLM_API_ERROR = 'LLM_API_ERROR';

    public const SANDBOX_TIMEOUT = 'SANDBOX_TIMEOUT';

    public const REPRODUCTION_FAILED = 'REPRODUCTION_FAILED';

    public const PATCH_SYNTHESIS_FAILED = 'PATCH_SYNTHESIS_FAILED';

    public const BUILD_FAILED = 'BUILD_FAILED';

    public const TEST_FAILED = 'TEST_FAILED';

    public const MAX_ATTEMPTS_EXCEEDED = 'MAX_ATTEMPTS_EXCEEDED';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $details = [],
    ) {}

    /**
     * Convert error DTO to array.
     *
     * @return array{code: string, message: string, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
