<?php

namespace App\Exceptions\MCP;

use App\Models\Incident;
use RuntimeException;

class RepositoryAccessDeniedException extends RuntimeException
{
    public function __construct(
        public string $requestedRepo,
        public string $authorizedRepo,
        public string $toolName,
        public ?Incident $incident = null,
    ) {
        parent::__construct(
            "Cross-repository access denied: requested [{$this->requestedRepo}] does not match authorized incident repository [{$this->authorizedRepo}] for tool [{$this->toolName}]."
        );
    }
}
