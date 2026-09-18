<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\CreateGitHubPullRequestJob;
use App\Models\Approval;
use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Services\Approval\ApprovalValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IncidentApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalValidationService $validator
    ) {}

    /**
     * Submit an authoritative, server-verified human approval for a patch artifact.
     */
    public function approve(Request $request, Incident $incident, PatchArtifact $patch): JsonResponse
    {
        $validated = $request->validate([
            'comment' => 'nullable|string|max:1000',
            'approved_by' => 'nullable|string|max:64',
        ]);

        return DB::transaction(function () use ($incident, $patch, $validated, $request) {
            // Verify all gates and invariants on the backend
            $qualityRun = $this->validator->assertCanBeApproved($incident, $patch);

            // Invalidate any past approvals for prior iterations
            Approval::where('incident_id', $incident->id)->update(['status' => 'SUPERSEDED']);

            $approver = (string) ($request->user()?->id ?? $validated['approved_by'] ?? 'operator_console');

            // Create immutable approval record bound to this specific patch_id
            $approval = Approval::create([
                'incident_id' => $incident->id,
                'patch_id' => $patch->id,
                'quality_gate_run_id' => $qualityRun->id,
                'approved_by' => $approver,
                'status' => 'APPROVED',
                'decision' => 'APPROVE',
                'comment' => $validated['comment'] ?? null,
                'approved_at' => now(),
            ]);

            $incident->update(['status' => IncidentStatus::APPROVED->value]);

            // Dispatch background GitHub mutation job
            CreateGitHubPullRequestJob::dispatch($incident, $patch, $approval);

            return response()->json([
                'success' => true,
                'message' => 'Patch successfully approved. Initiating branch and pull request creation.',
                'approval_id' => $approval->id,
            ]);
        });
    }

    /**
     * Reject a candidate patch and halt autonomous remediation.
     */
    public function reject(Request $request, Incident $incident, PatchArtifact $patch): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
            'approved_by' => 'nullable|string|max:64',
        ]);

        return DB::transaction(function () use ($incident, $patch, $validated, $request) {
            $approver = (string) ($request->user()?->id ?? $validated['approved_by'] ?? 'operator_console');

            Approval::create([
                'incident_id' => $incident->id,
                'patch_id' => $patch->id,
                'quality_gate_run_id' => $patch->latestQualityGateRun?->id,
                'approved_by' => $approver,
                'status' => 'REJECTED',
                'decision' => 'REJECT',
                'comment' => $validated['reason'],
                'approved_at' => now(),
            ]);

            // Human rejection halts autonomous remediation
            $incident->update([
                'status' => IncidentStatus::ESCALATED->value,
                'escalation_reason' => 'Human operator rejected patch: '.$validated['reason'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Patch candidate rejected. Incident escalated to security engineering.',
            ]);
        });
    }
}
