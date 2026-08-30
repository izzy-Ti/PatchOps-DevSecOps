<?php

namespace App\DTOs;

readonly class ReproductionResultDTO
{
    /**
     * Create a new reproduction result DTO instance.
     *
     * @param  array<string, mixed>  $artifacts
     */
    public function __construct(
        public bool $reproduced,
        public bool $sandboxSuccess,
        public ?string $pocScript = null,
        public ?string $stdout = null,
        public ?string $stderr = null,
        public ?int $exitCode = null,
        public ?string $summary = null,
        public ?string $failureReason = null,
        public array $artifacts = [],
        public float $executionTimeSeconds = 0.0,
    ) {}

    /**
     * Create a successful reproduction result DTO.
     *
     * @param  array<string, mixed>  $artifacts
     */
    public static function success(
        string $pocScript,
        string $stdout,
        string $stderr,
        string $summary,
        array $artifacts = [],
        float $time = 0.0,
    ): self {
        return new self(
            reproduced: true,
            sandboxSuccess: true,
            pocScript: $pocScript,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: 0,
            summary: $summary,
            artifacts: $artifacts,
            executionTimeSeconds: $time,
        );
    }

    /**
     * Create a failed reproduction result DTO (sandbox ran cleanly, but vulnerability was not reproduced).
     */
    public static function failed(
        string $reason,
        ?string $stdout = null,
        ?string $stderr = null,
        ?int $exitCode = null,
    ): self {
        return new self(
            reproduced: false,
            sandboxSuccess: true,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $exitCode,
            failureReason: $reason,
        );
    }

    /**
     * Create an infrastructure or execution error reproduction result DTO.
     */
    public static function error(string $errorMessage): self
    {
        return new self(
            reproduced: false,
            sandboxSuccess: false,
            failureReason: $errorMessage,
        );
    }
}
