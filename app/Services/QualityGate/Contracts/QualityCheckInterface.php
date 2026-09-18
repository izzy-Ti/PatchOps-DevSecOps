<?php

namespace App\Services\QualityGate\Contracts;

use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\QualityGate\DTOs\CheckResultDTO;

interface QualityCheckInterface
{
    /**
     * Unique machine identifier for this check.
     */
    public function name(): string;

    /**
     * Human-readable label for reporting.
     */
    public function label(): string;

    /**
     * Whether failure of this check terminates the pipeline immediately.
     */
    public function isCritical(): bool;

    /**
     * Execute the deterministic verification check.
     *
     * @param  array<string, mixed>  $context
     */
    public function execute(Incident $incident, PatchArtifact $patch, array $context = []): CheckResultDTO;
}
