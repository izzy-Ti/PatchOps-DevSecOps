<?php

namespace App\Services\Verification\DTOs;

class SecurityVerificationResultDTO
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly bool $passed,
        public readonly bool $exploitBlocked,
        public readonly int $statusCode = 403,
        public readonly ?string $payload = null,
        public readonly array $evidence = [],
        public readonly ?string $errorSummary = null,
        public readonly int $latencyMs = 0,
    ) {}
}
