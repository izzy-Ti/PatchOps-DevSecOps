<?php

namespace App\Events;

use App\Models\Incident;
use App\Models\RemediationRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RemediationCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Incident $incident,
        public RemediationRun $run,
    ) {}
}
