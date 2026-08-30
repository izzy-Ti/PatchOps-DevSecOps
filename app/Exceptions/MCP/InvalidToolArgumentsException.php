<?php

namespace App\Exceptions\MCP;

use InvalidArgumentException;

class InvalidToolArgumentsException extends InvalidArgumentException
{
    public function __construct(
        public string $toolName,
        string $message,
    ) {
        parent::__construct("Invalid parameters for tool [{$this->toolName}]: {$message}");
    }
}
