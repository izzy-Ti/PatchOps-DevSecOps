<?php

use App\Enums\IncidentPriority;
use App\Enums\IncidentStatus;
use App\Enums\VulnerabilitySeverity;
use App\Models\Incident;
use App\Models\User;
use App\Models\Vulnerability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('incidents table schema is correctly structured with all expanded fields', function () {
    expect(Schema::hasTable('incidents'))->toBeTrue()
        ->and(Schema::hasColumns('incidents', [
            'id',
            'vulnerability_id',
            'correlation_id',
            'incident_number',
            'title',
            'description',
            'severity',
            'priority',
            'status',
            'repository',
            'environment',
            'root_cause',
            'assigned_agent',
            'metadata',
            'user_id',
            'resolved_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

test('incident model casts enums and dates and auto-generates correlation_id and incident_number', function () {
    $user = User::factory()->create();
    $vuln = Vulnerability::factory()->create();

    $incident = Incident::create([
        'vulnerability_id' => $vuln->id,
        'title' => 'Critical RCE in Payment Gateway',
        'description' => 'Unauthenticated remote code execution found in webhook parsing.',
        'severity' => VulnerabilitySeverity::CRITICAL,
        'priority' => IncidentPriority::URGENT,
        'status' => IncidentStatus::TRIAGING,
        'repository' => 'izzy-Ti/payment-service',
        'environment' => 'sandbox',
        'root_cause' => 'Insecure deserialization of user input.',
        'assigned_agent' => 'TriageAgent',
        'metadata' => ['cve' => 'CVE-2026-9999'],
        'user_id' => $user->id,
        'resolved_at' => now(),
    ]);

    expect($incident->correlation_id)->not->toBeNull()
        ->and($incident->incident_number)->toStartWith('INC-')
        ->and($incident->severity)->toBe(VulnerabilitySeverity::CRITICAL)
        ->and($incident->priority)->toBe(IncidentPriority::URGENT)
        ->and($incident->status)->toBe(IncidentStatus::TRIAGING)
        ->and($incident->metadata)->toBe(['cve' => 'CVE-2026-9999'])
        ->and($incident->resolved_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($incident->vulnerability->id)->toBe($vuln->id)
        ->and($incident->user->id)->toBe($user->id);
});

test('incident model scopes filter by status milestones', function () {
    $received = Incident::factory()->create(['status' => IncidentStatus::RECEIVED]);
    $triaging = Incident::factory()->create(['status' => IncidentStatus::TRIAGING]);
    $resolved = Incident::factory()->create(['status' => IncidentStatus::RESOLVED]);
    $closed = Incident::factory()->create(['status' => IncidentStatus::CLOSED]);

    expect(Incident::received()->pluck('id')->all())->toBe([$received->id])
        ->and(Incident::triaging()->pluck('id')->all())->toBe([$triaging->id])
        ->and(Incident::resolved()->pluck('id')->all())->toBe([$resolved->id])
        ->and(Incident::closed()->pluck('id')->all())->toBe([$closed->id]);
});
