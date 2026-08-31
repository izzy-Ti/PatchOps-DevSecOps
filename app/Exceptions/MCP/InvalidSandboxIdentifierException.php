<?php

namespace App\Exceptions\MCP;

use RuntimeException;

class InvalidSandboxIdentifierException extends RuntimeException
{
    public function __construct(
        public string $providedId,
        public string $toolName,
    ) {
        parent::__construct(
            "Invalid sandbox identifier [{$this->providedId}] for tool [{$this->toolName}]. Raw Docker container IDs and un-prefixed identifiers are strictly forbidden."
        );
    }
}
