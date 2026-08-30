<?php

use App\Enums\IncidentStatus;
use App\Events\IncidentStatusChanged;
use App\Exceptions\InvalidIncidentStatusTransitionException;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentTransition;
use App\Services\Incident\IncidentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('RECEIVED to TRIAGING transitions successfully, updates status, writes history log, and fires event', function () {
    Event::fake([IncidentStatusChanged::class]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    $updatedIncident = $incident->transitionTo(
        targetStatus: IncidentStatus::TRIAGING,
        reason: 'Initial security triage',
        actorType: 'agent',
        actorId: 'TriageAgent-1',
        metadata: ['model' => 'gemini-1.5-pro'],
    );

    expect($updatedIncident->status)->toBe(IncidentStatus::TRIAGING);

    $this->assertDatabaseHas('incidents', [
        'id' => $incident->id,
        'status' => 'triaging',
    ]);

    $this->assertDatabaseHas('incident_transitions', [
        'incident_id' => $incident->id,
        'from_status' => 'received',
        'to_status' => 'triaging',
        'reason' => 'Initial security triage',
        'actor_type' => 'agent',
        'actor_id' => 'TriageAgent-1',
    ]);

    expect($incident->transitions)->toHaveCount(1)
        ->and($incident->transitions->first())->toBeInstanceOf(IncidentTransition::class)
        ->and($incident->transitions->first()->metadata)->toBe(['model' => 'gemini-1.5-pro']);

    Event::assertDispatched(IncidentStatusChanged::class, function (IncidentStatusChanged $event) use ($incident) {
        return $event->incident->id === $incident->id
            && $event->fromStatus === IncidentStatus::RECEIVED
            && $event->toStatus === IncidentStatus::TRIAGING
            && $event->reason === 'Initial security triage'
            && $event->context === ['model' => 'gemini-1.5-pro'];
    });

    expect(AuditLog::where('event', 'incident.status_changed')->where('correlation_id', $incident->correlation_id)->exists())
        ->toBeTrue();
});

test('RECEIVED to RESOLVED throws InvalidIncidentStatusTransitionException and leaves database record unchanged', function () {
    Event::fake([IncidentStatusChanged::class]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    try {
        $incident->transitionTo(IncidentStatus::RESOLVED);
        $this->fail('Expected InvalidIncidentStatusTransitionException was not thrown.');
    } catch (InvalidIncidentStatusTransitionException $e) {
        expect($e->currentStatus)->toBe(IncidentStatus::RECEIVED)
            ->and($e->targetStatus)->toBe(IncidentStatus::RESOLVED);

        $jsonResponse = $e->render(Request::create('/'));
        expect($jsonResponse->getStatusCode())->toBe(422)
            ->and($jsonResponse->getData(true))->toBe([
                'message' => 'Invalid incident status transition.',
                'error' => "Cannot transition from 'received' to 'resolved'.",
            ]);
    }

    $this->assertDatabaseHas('incidents', [
        'id' => $incident->id,
        'status' => 'received',
    ]);

    expect(IncidentTransition::where('incident_id', $incident->id)->count())->toBe(0);

    Event::assertNotDispatched(IncidentStatusChanged::class);
});

test('IncidentStateMachine transition method operates correctly when called directly', function () {
    Event::fake([IncidentStatusChanged::class]);

    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);
    $stateMachine = app(IncidentStateMachine::class);

    $stateMachine->transition(
        incident: $incident,
        targetStatus: IncidentStatus::TRIAGING,
        reason: 'Direct service call',
        actorType: 'system',
    );

    expect($incident->fresh()->status)->toBe(IncidentStatus::TRIAGING);
    Event::assertDispatched(IncidentStatusChanged::class);
    expect($incident->transitions)->toHaveCount(1);
});

test('incident tracks full transition history across linear success path', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    $linearPath = [
        IncidentStatus::TRIAGING,
        IncidentStatus::PRIORITIZED,
        IncidentStatus::REPRODUCING,
        IncidentStatus::REPRODUCED,
        IncidentStatus::PATCHING,
        IncidentStatus::VALIDATING,
        IncidentStatus::AWAITING_APPROVAL,
        IncidentStatus::PR_CREATED,
        IncidentStatus::CI_RUNNING,
        IncidentStatus::VERIFIED,
        IncidentStatus::RESOLVED,
        IncidentStatus::CLOSED,
    ];

    foreach ($linearPath as $nextStatus) {
        $incident->transitionTo($nextStatus, "Transitioning to {$nextStatus->value}");
        expect($incident->status)->toBe($nextStatus);
    }

    expect($incident->resolved_at)->not->toBeNull()
        ->and(AuditLog::where('event', 'incident.status_changed')->count())->toBe(12)
        ->and($incident->transitions()->count())->toBe(12);
});

test('incident handles validating to patching retry loop and failure paths', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    // Fast-forward to VALIDATING
    $incident->transitionTo(IncidentStatus::TRIAGING);
    $incident->transitionTo(IncidentStatus::PRIORITIZED);
    $incident->transitionTo(IncidentStatus::REPRODUCING);
    $incident->transitionTo(IncidentStatus::REPRODUCED);
    $incident->transitionTo(IncidentStatus::PATCHING);
    $incident->transitionTo(IncidentStatus::VALIDATING);

    // Loop back from VALIDATING to PATCHING
    $incident->transitionTo(IncidentStatus::PATCHING, 'Patch failed regression tests');
    expect($incident->status)->toBe(IncidentStatus::PATCHING);

    // Continue again
    $incident->transitionTo(IncidentStatus::VALIDATING);
    $incident->transitionTo(IncidentStatus::AWAITING_APPROVAL);
    expect($incident->status)->toBe(IncidentStatus::AWAITING_APPROVAL);

    expect($incident->transitions()->count())->toBe(9);
});

test('IncidentStatus helper methods verify terminal and human-awaiting states', function () {
    expect(IncidentStatus::RESOLVED->isTerminal())->toBeTrue()
        ->and(IncidentStatus::CLOSED->isTerminal())->toBeTrue()
        ->and(IncidentStatus::FAILED->isTerminal())->toBeTrue()
        ->and(IncidentStatus::TRIAGING->isTerminal())->toBeFalse()
        ->and(IncidentStatus::AWAITING_APPROVAL->isAwaitingHuman())->toBeTrue()
        ->and(IncidentStatus::ESCALATED->isAwaitingHuman())->toBeTrue()
        ->and(IncidentStatus::PATCHING->isAwaitingHuman())->toBeFalse();
});

test('incident active and awaiting approval scopes filter accurately', function () {
    Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);
    Incident::factory()->create(['status' => IncidentStatus::AWAITING_APPROVAL]);
    Incident::factory()->create(['status' => IncidentStatus::RESOLVED]);
    Incident::factory()->create(['status' => IncidentStatus::CLOSED]);
    Incident::factory()->create(['status' => IncidentStatus::FAILED]);

    expect(Incident::active()->count())->toBe(2)
        ->and(Incident::awaitingApproval()->count())->toBe(1)
        ->and(Incident::failed()->count())->toBe(1)
        ->and(Incident::resolved()->count())->toBe(1);
});
