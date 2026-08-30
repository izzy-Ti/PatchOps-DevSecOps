<?php

namespace App\Tools\Exceptions;

use RuntimeException;

class ToolNotFoundException extends RuntimeException
{
    public function __construct(string $toolName)
    {
        parent::__construct("Tool [{$toolName}] is not registered in ToolRegistry.");
    }
}
