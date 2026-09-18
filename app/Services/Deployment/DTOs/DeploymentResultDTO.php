<?php

namespace App\Services\Deployment\DTOs;

class DeploymentResultDTO
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly bool $deployed,
        public readonly ?string $deploymentId,
        public readonly string $environment,
        public readonly string $status,
        public readonly int $durationMs = 0,
        public readonly ?string $logs = null,
        public readonly array $details = [],
    ) {}
}
