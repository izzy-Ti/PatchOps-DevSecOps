<?php

namespace App\Jobs;

use App\Models\Approval;
use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\AuditLogger;
use App\Services\GitHub\GitHubPRService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateGitHubPullRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Incident $incident,
        public PatchArtifact $patch,
        public Approval $approval,
    ) {
        $this->onQueue('incidents');
    }

    public function handle(GitHubPRService $prService): void
    {
        Log::withContext(['correlation_id' => $this->incident->correlation_id]);

        Log::info("CreateGitHubPullRequestJob: Initiating GitHub mutation for approved incident [{$this->incident->incident_number}].");

        $pr = $prService->executeMutation($this->incident, $this->patch, $this->approval);

        AuditLogger::logSystemAction(
            event: 'github.pr_created',
            auditable: $this->incident,
            payload: [
                'incident_id' => $this->incident->id,
                'patch_id' => $this->patch->id,
                'approval_id' => $this->approval->id,
                'pr_number' => $pr->pr_number,
                'pr_url' => $pr->pr_url,
                'branch' => $pr->branch,
            ],
            correlationId: $this->incident->correlation_id,
        );

        $run = \App\Models\RemediationRun::create([
            'incident_id' => $this->incident->id,
            'patch_id' => $this->patch->id,
            'pull_request_id' => $pr->id,
            'status' => \App\Enums\RemediationStatus::PR_CREATED,
            'environment' => 'staging',
        ]);

        MonitorCIPipelineJob::dispatch($this->incident, $run);
    }
}

