<?php

namespace App\Exceptions\MCP;

use RuntimeException;
use Throwable;

class MCPExecutionException extends RuntimeException
{
    public function __construct(
        public string $serverName,
        public string $toolMethod,
        string $message,
        public string $errorCode = 'MCP_EXECUTION_ERROR',
        ?Throwable $previous = null,
    ) {
        parent::__construct("MCP execution error on [{$this->serverName}::{$this->toolMethod}]: {$message}", 0, $previous);
    }
}
