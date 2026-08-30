<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\VulnerabilitySeverity;
use App\Http\Controllers\Controller;
use App\Vulnerability\VulnerabilityIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GithubWebhookController extends Controller
{
    /**
     * Create a new GitHub webhook controller instance.
     */
    public function __construct(
        protected VulnerabilityIngestionService $ingestionService,
    ) {}

    /**
     * Handle incoming GitHub Dependabot webhook payload.
     */
    public function handle(Request $request): JsonResponse
    {
        if ($request->header('X-GitHub-Event') === 'ping' || $request->has('zen')) {
            return response()->json(['message' => 'pong']);
        }

        $secret = config('services.github.webhook_secret');
        $signature = $request->header('X-Hub-Signature-256');

        if ($secret && $signature) {
            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

            if (! hash_equals($expectedSignature, $signature)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid webhook signature.',
                ], 403);
            }
        }

        $incident = $this->ingestionService->ingest($request->all(), 'github');

        $severity = $incident->severity instanceof VulnerabilitySeverity ? $incident->severity->value : (string) $incident->severity;
        $status = $incident->status?->value ?? (string) $incident->status;

        return response()->json([
            'success' => true,
            'message' => 'Vulnerability ingested successfully',
            'data' => [
                'incident_id' => $incident->id,
                'incident_number' => $incident->incident_number,
                'correlation_id' => $incident->correlation_id,
                'source' => 'github',
                'severity' => $severity,
                'status' => $status,
            ],
        ], 201);
    }
}
