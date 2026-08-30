<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncidentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IncidentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Incident::query();

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->query('severity'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }

        if ($request->filled('repository')) {
            $query->where('repository', $request->query('repository'));
        }

        $incidents = $query->latest('id')->paginate($request->integer('per_page', 15));

        return IncidentResource::collection($incidents);
    }

    public function store(StoreIncidentRequest $request): IncidentResource
    {
        $user = $request->user();

        $incident = Incident::create([
            'title' => $request->validated('title'),
            'description' => $request->validated('description'),
            'severity' => $request->validated('severity', 'medium'),
            'status' => IncidentStatus::RECEIVED,
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
                    'severity' => $incident->severity?->value ?? (string) $incident->severity,
                ],
                correlationId: $incident->correlation_id,
            );
        }

        return new IncidentResource($incident);
    }

    public function show(Incident $incident): IncidentResource
    {
        $incident->loadMissing('vulnerability');

        return new IncidentResource($incident);
    }
}
