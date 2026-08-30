<?php

namespace App\Services\Sandbox\DTOs;

readonly class SandboxExecutionResultDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $success,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $durationSeconds,
        public bool $timedOut = false,
        public array $metadata = [],
    ) {}

    /**
     * Convert execution result into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'exit_code' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'duration_seconds' => $this->durationSeconds,
            'timed_out' => $this->timedOut,
            'metadata' => $this->metadata,
        ];
    }
}
