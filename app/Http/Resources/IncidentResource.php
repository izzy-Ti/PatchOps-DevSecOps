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
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incident_number' => $this->incident_number,
            'correlation_id' => $this->correlation_id,
            'title' => $this->title,
            'severity' => $this->severity?->value ?? (string) $this->severity,
            'priority' => $this->priority?->value ?? (string) $this->priority,
            'status' => $this->status?->value ?? (string) $this->status,
            'repository' => $this->repository,
            'environment' => $this->environment,
            'root_cause' => $this->root_cause,
            'assigned_agent' => $this->assigned_agent,
            'metadata' => $this->metadata ?? (object) [],
            'vulnerability' => new VulnerabilityResource($this->whenLoaded('vulnerability')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
        ];
    }
}
