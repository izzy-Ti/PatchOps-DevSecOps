<?php

namespace App\DTOs;

readonly class ReviewResultDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @param  array<string, mixed>  $failureEvidence
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $approved,
        public string $verdictSummary,
        public bool $vulnerabilityMitigated,
        public bool $regressionsDetected,
        public bool $buildPassed,
        public array $checks = [],
        public array $failureEvidence = [],
        public array $metadata = [],
    ) {}

    /**
     * Create an approved verdict.
     *
     * @param  array<int, array<string, mixed>>  $checks
     * @param  array<string, mixed>  $metadata
     */
    public static function approved(string $summary, array $checks = [], array $metadata = []): self
    {
        return new self(
            approved: true,
            verdictSummary: $summary,
            vulnerabilityMitigated: true,
            regressionsDetected: false,
            buildPassed: true,
            checks: $checks,
            failureEvidence: [],
            metadata: $metadata,
        );
    }

    /**
     * Create a rejected verdict with structured failure diagnostics.
     *
     * @param  array<int, string>|string  $details
     * @param  array<string, mixed>  $metadata
     */
    public static function rejected(
        string $reason,
        array|string $details = [],
        array $metadata = [],
        bool $vulnerabilityMitigated = false,
        bool $buildPassed = false,
        array $checks = [],
    ): self {
        $detailList = is_array($details) ? $details : [$details];

        return new self(
            approved: false,
            verdictSummary: $reason,
            vulnerabilityMitigated: $vulnerabilityMitigated,
            regressionsDetected: true,
            buildPassed: $buildPassed,
            checks: $checks,
            failureEvidence: [
                'stdout' => implode("\n", $detailList),
                'stderr' => '',
                'failed_tests' => $detailList,
                'synthesizer_guidance' => $reason,
            ],
            metadata: $metadata,
        );
    }

    /**
     * Construct from array payload.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public static function fromArray(array $data, array $metadata = []): self
    {
        $approved = (bool) ($data['approved'] ?? false);
        $summary = (string) ($data['verdict_summary'] ?? $data['summary'] ?? ($approved ? 'Validation succeeded.' : 'Validation checks failed.'));

        return new self(
            approved: $approved,
            verdictSummary: $summary,
            vulnerabilityMitigated: (bool) ($data['vulnerability_mitigated'] ?? false),
            regressionsDetected: (bool) ($data['regressions_detected'] ?? ! $approved),
            buildPassed: (bool) ($data['build_passed'] ?? $approved),
            checks: (array) ($data['checks'] ?? []),
            failureEvidence: (array) ($data['failure_evidence'] ?? []),
            metadata: $metadata,
        );
    }

    /**
     * Convert DTO to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'approved' => $this->approved,
            'verdict_summary' => $this->verdictSummary,
            'vulnerability_mitigated' => $this->vulnerabilityMitigated,
            'regressions_detected' => $this->regressionsDetected,
            'build_passed' => $this->buildPassed,
            'checks' => $this->checks,
            'failure_evidence' => $this->failureEvidence,
            'metadata' => $this->metadata,
        ];
    }
}
