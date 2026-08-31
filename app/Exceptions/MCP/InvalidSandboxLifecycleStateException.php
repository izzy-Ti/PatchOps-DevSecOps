<?php

namespace App\Exceptions\MCP;

use RuntimeException;

class InvalidSandboxLifecycleStateException extends RuntimeException
{
    public function __construct(
        public string $sandboxId,
        public string $currentState,
        public string $targetAction,
    ) {
        parent::__construct(
            "Invalid sandbox lifecycle transition for [{$this->sandboxId}]: Cannot execute action [{$this->targetAction}] in state [{$this->currentState}]."
        );
    }
}
