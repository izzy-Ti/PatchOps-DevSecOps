<?php

namespace App\Tools\Exceptions;

use InvalidArgumentException;

class InvalidToolArgumentException extends InvalidArgumentException
{
    public function __construct(string $toolName, string $message)
    {
        parent::__construct("Invalid arguments for tool [{$toolName}]: {$message}");
    }
}
