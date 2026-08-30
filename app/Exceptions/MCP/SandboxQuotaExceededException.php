<?php

namespace App\Exceptions\MCP;

use App\Tools\Enums\AgentRole;
use Exception;

class SandboxQuotaExceededException extends Exception
{
    public function __construct(
        public readonly AgentRole $role,
        public readonly int $currentSandboxes,
        public readonly int $maxSandboxes,
    ) {
        parent::__construct("Agent role [{$role->value}] exceeded sandbox quota ({$currentSandboxes}/{$maxSandboxes} instances).");
    }
}
