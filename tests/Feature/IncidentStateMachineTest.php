<?php

use App\Enums\IncidentStatus;
use App\Exceptions\InvalidIncidentStatusTransitionException;
use App\Models\AuditLog;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('incident follows full linear success path from RECEIVED to CLOSED', function () {
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
        ->and(AuditLog::where('event', 'incident.status_changed')->count())->toBe(12);
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
});

test('incident handles controlled failure branch and escalation', function () {
    $triageIncident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);
    $triageIncident->transitionTo(IncidentStatus::TRIAGING);
    $triageIncident->transitionTo(IncidentStatus::ESCALATED, 'Unsupported complex legacy code');
    expect($triageIncident->status)->toBe(IncidentStatus::ESCALATED);

    $reproIncident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);
    $reproIncident->transitionTo(IncidentStatus::TRIAGING);
    $reproIncident->transitionTo(IncidentStatus::PRIORITIZED);
    $reproIncident->transitionTo(IncidentStatus::REPRODUCING);
    $reproIncident->transitionTo(IncidentStatus::FAILED, 'Cannot reproduce in isolated container');
    expect($reproIncident->status)->toBe(IncidentStatus::FAILED);
});

test('disallowed state transition throws InvalidIncidentStatusTransitionException', function () {
    $incident = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);

    expect(fn () => $incident->transitionTo(IncidentStatus::RESOLVED))
        ->toThrow(InvalidIncidentStatusTransitionException::class);

    expect($incident->status)->toBe(IncidentStatus::RECEIVED);
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
