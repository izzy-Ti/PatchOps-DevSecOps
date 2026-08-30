<?php

namespace App\Exceptions\MCP;

use App\Models\Incident;
use App\Tools\Permissions\ToolScope;
use RuntimeException;

class ResourceAccessDeniedException extends RuntimeException
{
    public function __construct(
        public ToolScope|string $scope,
        public string $violatingResource,
        public string $reason,
        public ?Incident $incident = null,
    ) {
        $scopeStr = $this->scope instanceof ToolScope ? $this->scope->value : (string) $this->scope;
        $incidentNumber = $this->incident?->incident_number ?? 'UNKNOWN';

        parent::__construct(
            "Access Denied for Scope [{$scopeStr}] on Resource [{$this->violatingResource}] for Incident [{$incidentNumber}]: {$this->reason}"
        );
    }
}
