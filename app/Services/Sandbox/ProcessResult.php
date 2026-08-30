<?php

namespace App\Services\Sandbox;

readonly class ProcessResult
{
    public function __construct(
        public bool $success,
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $executionTimeSeconds = 0.0,
    ) {}
}
