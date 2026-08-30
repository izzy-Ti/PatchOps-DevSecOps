<?php

namespace App\Exceptions;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvalidIncidentStatusTransitionException extends RuntimeException
{
    /**
     * Create a new invalid incident status transition exception.
     */
    public function __construct(
        public readonly Incident|int|string|null $incident,
        public readonly IncidentStatus $currentStatus,
        public readonly IncidentStatus $targetStatus,
        ?string $message = null,
    ) {
        $incidentNumber = $incident instanceof Incident ? ($incident->incident_number ?? (string) $incident->id) : (string) $incident;
        $incidentDisplay = $incidentNumber !== '' ? " {$incidentNumber}" : '';

        $msg = $message ?? sprintf(
            "Cannot transition incident%s from '%s' to '%s'. Transition is not permitted by state machine.",
            $incidentDisplay,
            $currentStatus->value,
            $targetStatus->value
        );

        parent::__construct($msg);
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Invalid incident status transition.',
            'error' => sprintf("Cannot transition from '%s' to '%s'.", $this->currentStatus->value, $this->targetStatus->value),
        ], 422);
    }
}
