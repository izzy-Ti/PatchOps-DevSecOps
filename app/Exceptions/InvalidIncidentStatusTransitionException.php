<?php

namespace App\Exceptions;

use App\Enums\IncidentStatus;
use RuntimeException;

class InvalidIncidentStatusTransitionException extends RuntimeException
{
    /**
     * Create a new invalid incident status transition exception.
     */
    public function __construct(
        public readonly IncidentStatus $fromStatus,
        public readonly IncidentStatus $toStatus,
        public readonly ?int $incidentId = null,
        ?string $message = null,
    ) {
        $msg = $message ?? sprintf(
            'Cannot transition incident%s from [%s] to [%s].',
            $incidentId ? " #{$incidentId}" : '',
            $fromStatus->value,
            $toStatus->value
        );

        parent::__construct($msg);
    }
}
