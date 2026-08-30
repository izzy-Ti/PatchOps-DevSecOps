<?php

namespace App\Exceptions\MCP;

use App\Tools\Enums\AgentRole;
use Exception;

class ExecutionTimeoutExceededException extends Exception
{
    public function __construct(
        public readonly AgentRole $role,
        public readonly int $elapsedSeconds,
        public readonly int $maxSeconds,
    ) {
        parent::__construct("Agent role [{$role->value}] exceeded execution timeout budget ({$elapsedSeconds}s/{$maxSeconds}s).");
    }
}
