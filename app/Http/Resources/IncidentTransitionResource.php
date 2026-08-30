<?php

namespace App\Http\Resources;

use App\Models\IncidentTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IncidentTransition
 */
class IncidentTransitionResource extends JsonResource
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
            'incident_id' => $this->incident_id,
            'from_status' => $this->from_status?->value ?? (string) $this->from_status,
            'to_status' => $this->to_status?->value ?? (string) $this->to_status,
            'reason' => $this->reason,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'correlation_id' => $this->correlation_id,
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
