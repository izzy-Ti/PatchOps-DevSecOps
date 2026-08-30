<?php

namespace App\Exceptions\MCP;

use App\Models\Incident;
use RuntimeException;

class UnauthorizedCriticalActionException extends RuntimeException
{
    public function __construct(
        public string $toolName,
        public string $reason,
        public ?Incident $incident = null,
    ) {
        $incidentNumber = $this->incident?->incident_number ?? 'UNKNOWN';

        parent::__construct(
            "CRITICAL Tool Execution Denied [{$this->toolName}] on Incident [{$incidentNumber}]: {$this->reason}"
        );
    }
}
