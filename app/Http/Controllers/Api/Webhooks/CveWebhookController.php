<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\VulnerabilitySeverity;
use App\Http\Controllers\Controller;
use App\Vulnerability\VulnerabilityIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CveWebhookController extends Controller
{
    /**
     * Create a new CVE / Trivy webhook controller instance.
     */
    public function __construct(
        protected VulnerabilityIngestionService $ingestionService,
    ) {}

    /**
     * Handle incoming CVE / Trivy vulnerability payload.
     */
    public function handle(Request $request): JsonResponse
    {
        $incident = $this->ingestionService->ingest($request->all(), 'cve');

        $severity = $incident->severity instanceof VulnerabilitySeverity ? $incident->severity->value : (string) $incident->severity;
        $status = $incident->status?->value ?? (string) $incident->status;

        return response()->json([
            'success' => true,
            'message' => 'Vulnerability ingested successfully',
            'data' => [
                'incident_id' => $incident->id,
                'incident_number' => $incident->incident_number,
                'correlation_id' => $incident->correlation_id,
                'source' => 'cve',
                'severity' => $severity,
                'status' => $status,
            ],
        ], 201);
    }
}
