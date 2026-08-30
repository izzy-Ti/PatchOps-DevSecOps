<?php

namespace App\Exceptions\MCP;

use App\Tools\Enums\AgentRole;
use Exception;

class ToolCallBudgetExceededException extends Exception
{
    public function __construct(
        public readonly AgentRole $role,
        public readonly int $currentCalls,
        public readonly int $maxCalls,
    ) {
        parent::__construct("Agent role [{$role->value}] exceeded tool call budget ({$currentCalls}/{$maxCalls} calls).");
    }
}
