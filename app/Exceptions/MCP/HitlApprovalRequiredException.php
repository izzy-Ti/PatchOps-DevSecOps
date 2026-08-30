<?php

namespace App\Exceptions\MCP;

use App\Models\Incident;
use RuntimeException;

class HitlApprovalRequiredException extends RuntimeException
{
    public function __construct(
        public string $toolName,
        public string $reason,
        public ?Incident $incident = null,
        public ?string $requiredAction = null,
    ) {
        $incidentNumber = $this->incident?->incident_number ?? 'UNKNOWN';

        parent::__construct(
            "Human-In-The-Loop (HITL) Approval Required for Tool [{$this->toolName}] on Incident [{$incidentNumber}]: {$this->reason}"
        );
    }
}
