<?php

namespace App\DTOs;

use JsonSerializable;

readonly class ReproductionResultDTO implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $environment
     * @param  array<int, mixed>  $artifacts
     * @param  array<int, string>  $observations
     */
    public function __construct(
        public bool $reproduced,
        public int $exitCode,
        public string $command,
        public string $stdout,
        public string $stderr,
        public float $durationMs,
        public array $environment = [],
        public array $artifacts = [],
        public array $observations = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            reproduced: (bool) ($data['reproduced'] ?? false),
            exitCode: (int) ($data['exit_code'] ?? 0),
            command: (string) ($data['command'] ?? ''),
            stdout: (string) ($data['stdout'] ?? ''),
            stderr: (string) ($data['stderr'] ?? ''),
            durationMs: (float) ($data['duration_ms'] ?? 0.0),
            environment: (array) ($data['environment'] ?? []),
            artifacts: (array) ($data['artifacts'] ?? []),
            observations: (array) ($data['observations'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reproduced' => $this->reproduced,
            'exit_code' => $this->exitCode,
            'command' => $this->command,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'duration_ms' => $this->durationMs,
            'environment' => $this->environment,
            'artifacts' => $this->artifacts,
            'observations' => $this->observations,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Helper: check if exploit verification succeeded.
     */
    public function isReproduced(): bool
    {
        return $this->reproduced;
    }

    /**
     * Helper: Extract vulnerable file and line call sites from observations or artifacts.
     *
     * @return array<int, string>
     */
    public function getVulnerableCallSites(): array
    {
        $callSites = [];

        foreach ($this->observations as $obs) {
            if (preg_match('/(?:in|at)\s+([a-zA-Z0-9_\-\/\.]+\.[a-zA-Z0-9]+(?::\d+)?)/i', $obs, $matches)) {
                $callSites[] = $matches[1];
            }
        }

        foreach ($this->artifacts as $artifact) {
            if (is_array($artifact) && isset($artifact['call_site'])) {
                $callSites[] = (string) $artifact['call_site'];
            }
        }

        return array_unique($callSites);
    }

    /**
     * Helper: Extract PoC script or test file path if present.
     */
    public function getPoCScript(): ?string
    {
        foreach ($this->artifacts as $artifact) {
            if (is_array($artifact) && ($artifact['type'] ?? null) === 'poc_script' && ! empty($artifact['path'])) {
                return (string) $artifact['path'];
            }
        }

        if (preg_match('/(?:node|python|php)\s+([^\s]+\.(?:js|py|php|ts))/i', $this->command, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
