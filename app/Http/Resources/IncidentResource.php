<?php

namespace App\Http\Resources;

use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Incident
 */
class IncidentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incident_number' => $this->incident_number,
            'correlation_id' => $this->correlation_id,
            'vulnerability_id' => $this->vulnerability_id,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity?->value ?? $this->severity,
            'priority' => $this->priority?->value ?? $this->priority,
            'status' => $this->status?->value ?? $this->status,
            'repository' => $this->repository,
            'environment' => $this->environment,
            'root_cause' => $this->root_cause,
            'assigned_agent' => $this->assigned_agent,
            'metadata' => $this->metadata,
            'user_id' => $this->user_id,
            'resolved_at' => $this->resolved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
