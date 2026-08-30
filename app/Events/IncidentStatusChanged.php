<?php

namespace App\Events;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentStatusChanged
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new incident status changed event.
     *
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public Incident $incident,
        public IncidentStatus $fromStatus,
        public IncidentStatus $toStatus,
        public ?string $reason = null,
        public array $context = [],
    ) {}
}
