<?php

namespace App\Services\CICD\DTOs;

class CIPipelineResultDTO
{
    /**
     * @param  array<int, string>  $failedJobs
     * @param  array<int, string>  $failedSteps
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $passed,
        public readonly ?string $runId,
        public readonly string $commitSha,
        public readonly array $failedJobs = [],
        public readonly array $failedSteps = [],
        public readonly ?string $logs = null,
        public readonly int $durationMs = 0,
        public readonly ?string $errorSummary = null,
        public readonly array $metadata = [],
    ) {}
}
