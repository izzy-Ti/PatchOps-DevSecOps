<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentRunResource;
use App\Http\Resources\IncidentResource;
use App\Http\Resources\IncidentTransitionResource;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;

class IncidentTelemetryController extends Controller
{
    /**
     * Display the specified incident telemetry metadata.
     */
    public function show(Incident $incident): IncidentResource
    {
        $incident->loadMissing(['vulnerability']);

        return new IncidentResource($incident);
    }

    /**
     * Display a listing of agent runs for the incident.
     */
    public function agentRuns(Incident $incident): JsonResponse
    {
        $runs = $incident->agentRuns()->orderBy('started_at', 'asc')->get();

        return response()->json([
            'incident_id' => $incident->id,
            'incident_number' => $incident->incident_number,
            'total_runs' => $runs->count(),
            'data' => AgentRunResource::collection($runs),
        ]);
    }

    /**
     * Display a listing of state transitions for the incident.
     */
    public function transitions(Incident $incident): JsonResponse
    {
        $transitions = $incident->transitions()->orderBy('created_at', 'asc')->get();

        return response()->json([
            'incident_id' => $incident->id,
            'incident_number' => $incident->incident_number,
            'current_status' => $incident->status?->value ?? (string) $incident->status,
            'total_transitions' => $transitions->count(),
            'data' => IncidentTransitionResource::collection($transitions),
        ]);
    }
}
