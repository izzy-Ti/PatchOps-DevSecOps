<?php

namespace App\DTOs;

readonly class ValidationResultDTO
{
    /**
     * Create a new validation result DTO instance.
     *
     * @param  array<string, mixed>  $rawOutput
     */
    public function __construct(
        public bool $passed,
        public bool $testsPassed,
        public bool $buildPassed,
        public bool $securityScanPassed,
        public ?string $testOutput = null,
        public ?string $buildOutput = null,
        public ?string $feedback = null,
        public ?string $summary = null,
        public array $rawOutput = [],
    ) {}

    /**
     * Create a successful validation result DTO.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function success(
        string $testOutput,
        string $buildOutput,
        string $summary,
        array $raw = [],
    ): self {
        return new self(
            passed: true,
            testsPassed: true,
            buildPassed: true,
            securityScanPassed: true,
            testOutput: $testOutput,
            buildOutput: $buildOutput,
            summary: $summary,
            rawOutput: $raw,
        );
    }

    /**
     * Create a failed validation result DTO.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function failed(
        string $feedback,
        ?string $testOutput = null,
        ?string $buildOutput = null,
        array $raw = [],
    ): self {
        return new self(
            passed: false,
            testsPassed: false,
            buildPassed: false,
            securityScanPassed: false,
            testOutput: $testOutput,
            buildOutput: $buildOutput,
            feedback: $feedback,
            rawOutput: $raw,
        );
    }
}
