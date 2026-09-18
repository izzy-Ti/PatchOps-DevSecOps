<?php

namespace App\Jobs;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchPatchAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Incident $incident,
        public array $failureEvidence = [],
    ) {
        $this->onQueue('incidents');
    }

    public function handle(): void
    {
        if (! empty($this->failureEvidence)) {
            $this->incident->metadata = array_merge($this->incident->metadata ?? [], [
                'last_failure_evidence' => $this->failureEvidence,
                'last_validation_feedback' => $this->failureEvidence['stderr'] ?? $this->failureEvidence['stdout'] ?? null,
            ]);
            $this->incident->save();
        }

        GeneratePatchJob::dispatch($this->incident)->onQueue('incidents');
    }
}
