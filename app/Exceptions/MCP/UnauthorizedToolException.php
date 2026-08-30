<?php

namespace App\Exceptions\MCP;

use App\Tools\Enums\AgentRole;
use RuntimeException;

class UnauthorizedToolException extends RuntimeException
{
    public function __construct(
        public AgentRole $role,
        public string $toolName,
        string $message = '',
    ) {
        $msg = $message ?: "Agent role [{$this->role->value}] is unauthorized to execute tool [{$this->toolName}].";
        parent::__construct($msg);
    }
}
