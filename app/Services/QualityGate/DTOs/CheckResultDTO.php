<?php

namespace App\Services\QualityGate\DTOs;

class CheckResultDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly int $exitCode = 0,
        public readonly ?string $stdout = null,
        public readonly ?string $stderr = null,
        public readonly float $durationMs = 0.0,
        public readonly bool $isCritical = true,
        public readonly ?string $failureClassification = null,
        public readonly array $metadata = [],
    ) {}

    public function passed(): bool
    {
        return $this->status === 'passed' && $this->exitCode === 0;
    }

    /**
     * Factory for successful check result.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function pass(
        string $name,
        ?string $stdout = null,
        float $durationMs = 0.0,
        bool $isCritical = true,
        array $metadata = []
    ): self {
        return new self(
            name: $name,
            status: 'passed',
            exitCode: 0,
            stdout: $stdout,
            durationMs: $durationMs,
            isCritical: $isCritical,
            metadata: $metadata,
        );
    }

    /**
     * Factory for failed check result.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function fail(
        string $name,
        int $exitCode = 1,
        ?string $stderr = null,
        ?string $stdout = null,
        float $durationMs = 0.0,
        bool $isCritical = true,
        ?string $failureClassification = 'test_assertion_failure',
        array $metadata = []
    ): self {
        return new self(
            name: $name,
            status: 'failed',
            exitCode: $exitCode !== 0 ? $exitCode : 1,
            stdout: $stdout,
            stderr: $stderr,
            durationMs: $durationMs,
            isCritical: $isCritical,
            failureClassification: $failureClassification,
            metadata: $metadata,
        );
    }

    /**
     * Convert DTO to array attributes for QualityGateCheck model creation.
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'check_type' => $this->name,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'duration_ms' => $this->durationMs,
            'is_critical' => $this->isCritical,
            'failure_classification' => $this->failureClassification,
        ];
    }
}
