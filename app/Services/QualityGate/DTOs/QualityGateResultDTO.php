<?php

namespace App\Services\QualityGate\DTOs;

class QualityGateResultDTO
{
    /**
     * @param  array<int, CheckResultDTO>  $checks
     * @param  array<string, mixed>  $failureEvidence
     */
    public function __construct(
        public readonly bool $passed,
        public readonly int $iteration,
        public readonly array $checks = [],
        public readonly float $durationMs = 0.0,
        public readonly ?CheckResultDTO $failedCheck = null,
        public readonly array $failureEvidence = [],
    ) {}

    public function totalChecks(): int
    {
        return count($this->checks);
    }

    public function passedChecksCount(): int
    {
        return count(array_filter($this->checks, fn (CheckResultDTO $c) => $c->passed()));
    }

    public function failedChecksCount(): int
    {
        return count(array_filter($this->checks, fn (CheckResultDTO $c) => ! $c->passed()));
    }
}
