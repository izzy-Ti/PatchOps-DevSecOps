<?php

namespace App\DTOs;

readonly class PatchResultDTO
{
    /**
     * Create a new patch result DTO instance.
     *
     * @param  array<string>  $changedFiles  List of modified file paths
     * @param  array<string>  $testsAdded  List of created/modified test file paths
     * @param  array<string, mixed>  $rawOutput
     */
    public function __construct(
        public bool $success,
        public ?string $rootCause = null,
        public ?string $fixSummary = null,
        public ?string $diff = null,
        public array $changedFiles = [],
        public array $testsAdded = [],
        public ?string $failureReason = null,
        public array $rawOutput = [],
    ) {}

    /**
     * Create a successful patch result DTO.
     *
     * @param  array<string>  $changedFiles
     * @param  array<string>  $testsAdded
     * @param  array<string, mixed>  $raw
     */
    public static function success(
        string $rootCause,
        string $fixSummary,
        string $diff,
        array $changedFiles = [],
        array $testsAdded = [],
        array $raw = [],
    ): self {
        return new self(
            success: true,
            rootCause: $rootCause,
            fixSummary: $fixSummary,
            diff: $diff,
            changedFiles: $changedFiles,
            testsAdded: $testsAdded,
            rawOutput: $raw,
        );
    }

    /**
     * Create a failed patch result DTO.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function failed(string $reason, array $raw = []): self
    {
        return new self(
            success: false,
            failureReason: $reason,
            rawOutput: $raw,
        );
    }

    /**
     * Determine whether the patch result is structurally valid and non-empty.
     */
    public function isValid(): bool
    {
        if (! $this->success) {
            return false;
        }

        if (empty(trim((string) $this->diff))) {
            return false;
        }

        if (empty(trim((string) $this->rootCause))) {
            return false;
        }

        if (empty(trim((string) $this->fixSummary))) {
            return false;
        }

        return true;
    }
}
