<?php

namespace App\DTOs;

readonly class DiffAuditResultDTO
{
    /**
     * @param  array<int, string>  $violations
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $passed,
        public array $violations = [],
        public array $metadata = [],
    ) {}

    /**
     * Convert the DTO to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'violations' => $this->violations,
            'metadata' => $this->metadata,
        ];
    }
}
