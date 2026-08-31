<?php

namespace App\Services\Sandbox\DTOs;

class SandboxContextDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $sandboxId,
        public string $incidentId,
        public string $runtime,
        public string $state,
        public string $createdAt,
        public ?string $destroyedAt = null,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sandboxId: (string) ($data['sandbox_id'] ?? $data['workspace_id'] ?? ''),
            incidentId: (string) ($data['incident_id'] ?? ''),
            runtime: (string) ($data['runtime'] ?? $data['ecosystem'] ?? 'node20'),
            state: (string) ($data['state'] ?? $data['status'] ?? 'INITIALIZED'),
            createdAt: (string) ($data['created_at'] ?? now()->toIso8601String()),
            destroyedAt: isset($data['destroyed_at']) ? (string) $data['destroyed_at'] : null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sandbox_id' => $this->sandboxId,
            'incident_id' => $this->incidentId,
            'runtime' => $this->runtime,
            'state' => $this->state,
            'created_at' => $this->createdAt,
            'destroyed_at' => $this->destroyedAt,
            'metadata' => $this->metadata,
        ];
    }
}
