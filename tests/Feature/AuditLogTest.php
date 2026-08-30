<?php

use App\Http\Middleware\EnsureCorrelationId;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('audit_logs table schema includes correlation_id and excludes updated_at', function () {
    expect(Schema::hasTable('audit_logs'))->toBeTrue()
        ->and(Schema::hasColumns('audit_logs', [
            'id',
            'correlation_id',
            'actor_type',
            'actor_id',
            'event',
            'auditable_type',
            'auditable_id',
            'payload',
            'ip_address',
            'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse();
});

test('audit logs are immutable and throw exception on update or delete', function () {
    $user = User::factory()->admin()->create();

    $log = AuditLogger::logUserAction(
        user: $user,
        event: 'patch.approved',
        auditable: $user,
        payload: ['cve' => 'CVE-2024-1234'],
        ip: '127.0.0.1',
        correlationId: 'INC-TEST001'
    );

    expect($log->exists)->toBeTrue()
        ->and($log->correlation_id)->toBe('INC-TEST001')
        ->and($log->actor_type)->toBe('user')
        ->and($log->actor_id)->toBe($user->id)
        ->and($log->event)->toBe('patch.approved')
        ->and($log->payload)->toBe(['cve' => 'CVE-2024-1234'])
        ->and($log->ip_address)->toBe('127.0.0.1')
        ->and($log->created_at)->not->toBeNull()
        ->and($log->auditable->id)->toBe($user->id);

    expect(fn () => $log->update(['event' => 'modified']))
        ->toThrow(RuntimeException::class, 'Audit logs are immutable and cannot be updated.');

    expect(fn () => $log->delete())
        ->toThrow(RuntimeException::class, 'Audit logs are immutable and cannot be deleted.');
});

test('scopeForCorrelation filters logs by correlation ID', function () {
    $user = User::factory()->admin()->create();

    $log1 = AuditLogger::logUserAction($user, 'step.one', correlationId: 'INC-SCOPE-A');
    $log2 = AuditLogger::logUserAction($user, 'step.two', correlationId: 'INC-SCOPE-A');
    $log3 = AuditLogger::logUserAction($user, 'step.three', correlationId: 'INC-SCOPE-B');

    $results = AuditLog::forCorrelation('INC-SCOPE-A')->get();

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->all())->toBe([$log1->id, $log2->id])
        ->and($results->pluck('id')->all())->not->toContain($log3->id);
});

test('audit logger records agent action and resolves container correlation id', function () {
    app()->instance('correlation_id', 'INC-BOUND99');

    $log = AuditLogger::logAgentAction(
        agentName: 'TriageAgent',
        event: 'tool.invoked',
        payload: ['tool' => 'sandbox_run', 'status' => 'success']
    );

    expect($log->correlation_id)->toBe('INC-BOUND99')
        ->and($log->actor_type)->toBe('agent')
        ->and($log->actor_id)->toBeNull()
        ->and($log->event)->toBe('tool.invoked')
        ->and($log->payload)->toMatchArray([
            'agent' => 'TriageAgent',
            'tool' => 'sandbox_run',
            'status' => 'success',
        ])
        ->and($log->ip_address)->toBeNull();
});

test('audit logger records system action and auto-generates correlation id if none set', function () {
    $log = AuditLogger::logSystemAction(
        event: 'policy.rejected',
        payload: ['reason' => 'Rate limit exceeded']
    );

    expect($log->correlation_id)->toStartWith('INC-')
        ->and($log->actor_type)->toBe('system')
        ->and($log->actor_id)->toBeNull()
        ->and($log->event)->toBe('policy.rejected')
        ->and($log->payload)->toBe(['reason' => 'Rate limit exceeded'])
        ->and($log->ip_address)->toBeNull();
});

test('EnsureCorrelationId middleware sets correlation id from header or generates a new one', function () {
    $middleware = new EnsureCorrelationId;

    // Test with incoming X-Correlation-ID header
    Log::shouldReceive('withContext')->once()->with(['correlation_id' => 'INC-CUSTOM123']);
    $requestWithHeader = Request::create('/test', 'GET', server: ['HTTP_X_CORRELATION_ID' => 'INC-CUSTOM123']);
    $response = $middleware->handle($requestWithHeader, function ($req) {
        expect(app('correlation_id'))->toBe('INC-CUSTOM123');

        return new Response('OK');
    });

    expect($response->headers->get('X-Correlation-ID'))->toBe('INC-CUSTOM123');

    // Test without incoming header (auto-generation)
    Log::shouldReceive('withContext')->once()->withArgs(function ($context) {
        return isset($context['correlation_id']) && str_starts_with($context['correlation_id'], 'INC-');
    });

    $requestWithoutHeader = Request::create('/test', 'GET');
    $responseGenerated = $middleware->handle($requestWithoutHeader, function ($req) {
        expect(app('correlation_id'))->toStartWith('INC-');

        return new Response('OK');
    });

    expect($responseGenerated->headers->get('X-Correlation-ID'))->toStartWith('INC-');
});
