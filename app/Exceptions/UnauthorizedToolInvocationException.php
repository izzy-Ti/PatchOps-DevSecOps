<?php

namespace App\Exceptions;

use App\Models\Incident;
use RuntimeException;

class UnauthorizedToolInvocationException extends RuntimeException
{
    public function __construct(
        public string $role,
        public string $tool,
        public ?Incident $incident = null,
        string $message = '',
    ) {
        $msg = $message ?: "Agent role [{$this->role}] is not authorized to invoke tool [{$this->tool}].";
        parent::__construct($msg);
    }
}
