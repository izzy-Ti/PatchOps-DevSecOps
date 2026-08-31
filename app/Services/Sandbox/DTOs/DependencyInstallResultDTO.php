<?php

namespace App\Services\Sandbox\DTOs;

class DependencyInstallResultDTO
{
    public function __construct(
        public bool $success,
        public string $sandboxId,
        public string $ecosystem,
        public string $manifestDetected,
        public string $commandExecuted,
        public int $exitCode,
        public float $durationMs,
        public string $stdout,
        public string $stderr,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: (bool) ($data['success'] ?? ($data['exit_code'] ?? 1) === 0),
            sandboxId: (string) ($data['sandbox_id'] ?? ''),
            ecosystem: (string) ($data['ecosystem'] ?? 'unknown'),
            manifestDetected: (string) ($data['manifest_detected'] ?? 'none'),
            commandExecuted: (string) ($data['command_executed'] ?? ''),
            exitCode: (int) ($data['exit_code'] ?? 0),
            durationMs: (float) ($data['duration_ms'] ?? 0.0),
            stdout: (string) ($data['stdout'] ?? ''),
            stderr: (string) ($data['stderr'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'sandbox_id' => $this->sandboxId,
            'ecosystem' => $this->ecosystem,
            'manifest_detected' => $this->manifestDetected,
            'command_executed' => $this->commandExecuted,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
        ];
    }
}
