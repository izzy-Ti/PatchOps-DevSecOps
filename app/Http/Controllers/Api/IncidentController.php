<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Incident::query();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        $incidents = $query->latest('id')->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => IncidentResource::collection($incidents),
            'meta' => [
                'current_page' => $incidents->currentPage(),
                'last_page' => $incidents->lastPage(),
                'per_page' => $incidents->perPage(),
                'total' => $incidents->total(),
            ],
        ]);
    }

    public function store(StoreIncidentRequest $request): JsonResponse
    {
        $user = $request->user();

        $incident = Incident::create([
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'severity' => $request->validated('severity', 'medium'),
            'status' => 'open',
            'metadata' => $request->validated('metadata'),
            'user_id' => $user?->id,
        ]);

        if ($user) {
            AuditLogger::logUserAction(
                user: $user,
                event: 'incident.created',
                auditable: $incident,
                payload: [
                    'title' => $incident->title,
                    'severity' => $incident->severity,
                ],
                correlationId: $incident->correlation_id,
            );
        }

        return response()->json([
            'success' => true,
            'data' => new IncidentResource($incident),
            'correlation_id' => $incident->correlation_id,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new IncidentResource($incident),
        ]);
    }
}
