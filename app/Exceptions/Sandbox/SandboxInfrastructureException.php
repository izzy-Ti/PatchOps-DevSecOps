<?php

namespace App\Exceptions\Sandbox;

use RuntimeException;
use Throwable;

class SandboxInfrastructureException extends RuntimeException
{
    public function __construct(
        string $message = 'Transient sandbox infrastructure or platform error occurred.',
        public bool $retryable = true,
        public array $details = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
