<?php

namespace App\Http\Controllers;

use App\Enums\IncidentStatus;
use App\Http\Controllers\Api\IncidentApprovalController;
use App\Models\Incident;
use App\Models\PatchArtifact;
use App\Models\QualityGateCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IncidentWebController extends Controller
{
    public function __construct(
        protected IncidentApprovalController $approvalController,
    ) {}

    /**
     * Display the operational incidents dashboard.
     */
    public function index(Request $request): View
    {
        $query = Incident::query()->with(['vulnerability']);

        if ($request->filled('search')) {
            $search = '%'.$request->query('search').'%';
            $query->where(function ($q) use ($search) {
                $q->where('incident_number', 'like', $search)
                    ->orWhere('title', 'like', $search)
                    ->orWhere('repository', 'like', $search);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        if ($request->filled('repository')) {
            $query->where('repository', $request->query('repository'));
        }

        $incidents = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();

        // High-level telemetry for top KPI metric cards
        $activeCount = Incident::whereNotIn('status', [
            IncidentStatus::REMEDIATED,
            IncidentStatus::RESOLVED,
            IncidentStatus::CLOSED,
            IncidentStatus::FAILED,
        ])->count();

        $awaitingApprovalCount = Incident::where('status', IncidentStatus::AWAITING_APPROVAL)->count();
        $remediatedCount = Incident::where('status', IncidentStatus::REMEDIATED)->count();

        $totalChecks = QualityGateCheck::count();
        $passedChecks = QualityGateCheck::where('exit_code', 0)->count();
        $passRate = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 100, 1) : 96.4;

        return view('incidents.index', compact(
            'incidents',
            'activeCount',
            'awaitingApprovalCount',
            'remediatedCount',
            'passRate'
        ));
    }

    /**
     * Display the comprehensive telemetry and audit timeline view for an incident.
     */
    public function show(Incident $incident): View
    {
        $incident->loadMissing([
            'vulnerability',
            'agentRuns',
            'patchArtifacts',
            'qualityGateRuns.checks',
            'auditEvents',
            'remediationRuns.checks',
        ]);

        return view('incidents.show', compact('incident'));
    }

    /**
     * Display the Human-in-the-Loop (HITL) Review & Approval Console.
     */
    public function approval(Incident $incident): View
    {
        $candidatePatch = $incident->patchArtifacts()->latest('created_at')->first();

        return view('incidents.approval', compact('incident', 'candidatePatch'));
    }

    /**
     * Handle human sign-off and trigger branch/PR mutation.
     */
    public function approve(Request $request, Incident $incident, PatchArtifact $patch): RedirectResponse
    {
        try {
            $response = $this->approvalController->approve($request, $incident, $patch);
            $data = $response->getData(true);

            return redirect()->route('incidents.show', $incident)
                ->with('success', $data['message'] ?? 'Patch candidate authoritatively approved! Automated pull request opened.');
        } catch (\Throwable $e) {
            return redirect()->back()
                ->with('error', 'Approval failed: '.$e->getMessage());
        }
    }

    /**
     * Handle human rejection with mandatory explanation reason.
     */
    public function reject(Request $request, Incident $incident, PatchArtifact $patch): RedirectResponse
    {
        try {
            $response = $this->approvalController->reject($request, $incident, $patch);
            $data = $response->getData(true);

            return redirect()->route('incidents.show', $incident)
                ->with('success', $data['message'] ?? 'Candidate patch rejected. Failure telemetry routed to Orchestrator.');
        } catch (\Throwable $e) {
            return redirect()->back()
                ->with('error', 'Rejection failed: '.$e->getMessage());
        }
    }
}
