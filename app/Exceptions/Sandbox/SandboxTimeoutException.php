<?php

namespace App\Exceptions\Sandbox;

use RuntimeException;
use Throwable;

class SandboxTimeoutException extends RuntimeException
{
    public function __construct(
        string $message = 'Sandbox command execution or workspace TTL exceeded timeout limit.',
        public int $timeoutSeconds = 600,
        public array $details = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
