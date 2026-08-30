<?php

use App\Enums\IncidentStatus;
use App\Events\IncidentStatusChanged;
use App\Exceptions\InvalidIncidentStatusTransitionException;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentTransition;
use App\Services\Incident\IncidentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('IncidentStateMachine transition executes atomically inside database transaction', function () {
    Event::fake([IncidentStatusChanged::class]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);
    $stateMachine = app(IncidentStateMachine::class);

    $stateMachine->transition(
        incident: $incident,
        targetStatus: IncidentStatus::TRIAGING,
        reason: 'Automated triage worker initiated',
        actorType: 'agent',
        actorId: 'triage-agent',
        metadata: ['queue' => 'incidents'],
    );

    $incident->refresh();

    // 1. Status updated
    expect($incident->status)->toBe(IncidentStatus::TRIAGING);

    // 2. Incident transition record persisted
    expect(IncidentTransition::where('incident_id', $incident->id)->count())->toBe(1);
    $transition = IncidentTransition::where('incident_id', $incident->id)->first();
    expect($transition->from_status)->toBe(IncidentStatus::RECEIVED)
        ->and($transition->to_status)->toBe(IncidentStatus::TRIAGING)
        ->and($transition->reason)->toBe('Automated triage worker initiated');

    // 3. Audit log recorded
    expect(AuditLog::where('event', 'incident.status_changed')->count())->toBe(1);

    // 4. Domain event dispatched
    Event::assertDispatched(IncidentStatusChanged::class, function ($event) use ($incident) {
        return $event->incident->id === $incident->id
            && $event->fromStatus === IncidentStatus::RECEIVED
            && $event->toStatus === IncidentStatus::TRIAGING;
    });
});

test('Invalid transition triggers rollback and leaves no partial transition or audit logs', function () {
    Event::fake([IncidentStatusChanged::class]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);
    $stateMachine = app(IncidentStateMachine::class);

    $initialTransitionsCount = IncidentTransition::count();
    $initialAuditLogsCount = AuditLog::count();

    // Attempt illegal transition from RECEIVED directly to VERIFIED
    try {
        $stateMachine->transition(
            incident: $incident,
            targetStatus: IncidentStatus::VERIFIED,
            reason: 'Illegal skip',
        );
        $this->fail('Expected InvalidIncidentStatusTransitionException was not thrown.');
    } catch (InvalidIncidentStatusTransitionException $e) {
        expect($e->currentStatus)->toBe(IncidentStatus::RECEIVED)
            ->and($e->targetStatus)->toBe(IncidentStatus::VERIFIED);
    }

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::RECEIVED)
        ->and(IncidentTransition::count())->toBe($initialTransitionsCount)
        ->and(AuditLog::count())->toBe($initialAuditLogsCount);

    Event::assertNotDispatched(IncidentStatusChanged::class);
});
