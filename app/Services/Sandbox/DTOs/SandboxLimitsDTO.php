<?php

namespace App\Services\Sandbox\DTOs;

readonly class SandboxLimitsDTO
{
    public function __construct(
        public string $cpu = '2.0',
        public string $memory = '2g',
        public string $memorySwap = '2g',
        public int $timeoutSeconds = 600,
        public string $tmpfsSize = '512m',
        public int $pidsLimit = 100,
        public string $network = 'none',
        public int $maxOutputBytes = 50000,
    ) {}

    /**
     * Build instance from application configuration.
     */
    public static function fromConfig(): self
    {
        return new self(
            cpu: (string) config('sandbox.limits.cpu', '2.0'),
            memory: (string) config('sandbox.limits.memory', '2g'),
            memorySwap: (string) config('sandbox.limits.memory_swap', '2g'),
            timeoutSeconds: (int) config('sandbox.limits.timeout_seconds', 600),
            tmpfsSize: (string) config('sandbox.limits.tmpfs_size', '512m'),
            pidsLimit: (int) config('sandbox.limits.pids_limit', 100),
            network: (string) config('sandbox.limits.default_network', 'none'),
            maxOutputBytes: (int) config('sandbox.limits.max_output_bytes', 50000),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cpu' => $this->cpu,
            'memory' => $this->memory,
            'memory_swap' => $this->memorySwap,
            'timeout_seconds' => $this->timeoutSeconds,
            'tmpfs_size' => $this->tmpfsSize,
            'pids_limit' => $this->pidsLimit,
            'network' => $this->network,
            'max_output_bytes' => $this->maxOutputBytes,
        ];
    }
}
