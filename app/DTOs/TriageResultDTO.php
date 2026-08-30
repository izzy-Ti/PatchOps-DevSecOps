<?php

namespace App\DTOs;

readonly class TriageResultDTO
{
    /**
     * Create a new triage result DTO instance.
     *
     * @param  array<string, mixed>  $rawOutput
     */
    public function __construct(
        public bool $success,
        public ?string $severity = null,
        public ?string $priority = null,
        public ?bool $productionExposed = null,
        public ?string $affectedComponent = null,
        public ?string $reason = null,
        public array $rawOutput = [],
        public ?string $errorMessage = null,
    ) {}

    /**
     * Create a successful triage result DTO.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public static function success(array $data, array $raw = []): self
    {
        return new self(
            success: true,
            severity: isset($data['severity']) ? strtolower((string) $data['severity']) : null,
            priority: isset($data['priority']) ? strtolower((string) $data['priority']) : null,
            productionExposed: isset($data['production_exposed']) ? (bool) $data['production_exposed'] : null,
            affectedComponent: isset($data['affected_component']) ? (string) $data['affected_component'] : null,
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            rawOutput: $raw,
        );
    }

    /**
     * Create a failed triage result DTO.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function failure(string $errorMessage, array $raw = []): self
    {
        return new self(
            success: false,
            rawOutput: $raw,
            errorMessage: $errorMessage,
        );
    }

    /**
     * Determine whether the triage result is structurally and semantically valid.
     */
    public function isValid(): bool
    {
        if (! $this->success) {
            return false;
        }

        $validSeverities = ['critical', 'high', 'medium', 'low'];
        $validPriorities = ['critical', 'urgent', 'high', 'medium', 'low'];

        if (! $this->severity || ! in_array(strtolower($this->severity), $validSeverities, true)) {
            return false;
        }

        if (! $this->priority || ! in_array(strtolower($this->priority), $validPriorities, true)) {
            return false;
        }

        if ($this->productionExposed === null) {
            return false;
        }

        if (empty(trim((string) $this->affectedComponent))) {
            return false;
        }

        if (empty(trim((string) $this->reason))) {
            return false;
        }

        return true;
    }
}
