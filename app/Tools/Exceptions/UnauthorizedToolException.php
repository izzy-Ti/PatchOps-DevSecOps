<?php

namespace App\Tools\Exceptions;

use App\Tools\Permissions\AgentRole;
use App\Tools\Permissions\ToolPermission;
use RuntimeException;

class UnauthorizedToolException extends RuntimeException
{
    public function __construct(
        public string $toolName,
        public AgentRole $role,
        public ToolPermission $requiredPermission,
    ) {
        parent::__construct(
            "Agent role [{$this->role->value}] is unauthorized to execute tool [{$this->toolName}]. Requires permission [{$this->requiredPermission->value}]."
        );
    }
}
