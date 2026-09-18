<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Observability\IncidentTimelineService;
use App\Services\Observability\MetricsService;
use Illuminate\Http\JsonResponse;

class IncidentObservabilityController extends Controller
{
    /**
     * Get end-to-end chronological timeline for an incident.
     */
    public function timeline(Incident $incident, IncidentTimelineService $timelineService): JsonResponse
    {
        $timeline = $timelineService->buildTimeline($incident);

        return response()->json([
            'incident_id' => $incident->id,
            'incident_number' => $incident->incident_number,
            'total_events' => count($timeline),
            'data' => $timeline,
        ]);
    }

    /**
     * Get distributed trace hierarchy and tool executions for an incident.
     */
    public function trace(Incident $incident): JsonResponse
    {
        $traces = $incident->traces()
            ->with(['agentRuns', 'toolCalls'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'incident_id' => $incident->id,
            'incident_number' => $incident->incident_number,
            'total_traces' => $traces->count(),
            'data' => $traces,
        ]);
    }

    /**
     * Get aggregate platform operational metrics (MTTR, token usage, pass rates).
     */
    public function metrics(MetricsService $metricsService): JsonResponse
    {
        return response()->json([
            'data' => $metricsService->getMetrics(),
        ]);
    }
}
