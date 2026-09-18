<?php

namespace App\Services\Verification\DTOs;

class HealthCheckResultDTO
{
    /**
     * @param  array<string, bool>  $checks
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly bool $passed,
        public readonly int $statusCode,
        public readonly int $latencyMs = 0,
        public readonly array $checks = [],
        public readonly ?string $endpoint = null,
        public readonly array $evidence = [],
    ) {}
}
