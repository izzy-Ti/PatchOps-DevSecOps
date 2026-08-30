<?php

namespace App\Exceptions\MCP;

use App\Models\Incident;
use RuntimeException;

class ForbiddenHostCapabilityException extends RuntimeException
{
    public function __construct(
        public string $capability,
        public string $reason,
        public ?Incident $incident = null,
        public ?string $violatingPayload = null,
    ) {
        $incidentNumber = $this->incident?->incident_number ?? 'UNKNOWN';

        parent::__construct(
            "Forbidden Capability Violation [{$this->capability}] on Incident [{$incidentNumber}]: {$this->reason}"
        );
    }
}
