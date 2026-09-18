<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IncidentApprovalController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\IncidentObservabilityController;
use App\Http\Controllers\Api\IncidentTelemetryController;
use App\Http\Controllers\Api\Webhooks\CveWebhookController;
use App\Http\Controllers\Api\Webhooks\GithubWebhookController;
use App\Http\Controllers\Api\Webhooks\SnykWebhookController;
use App\Http\Middleware\EnsureCorrelationId;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureCorrelationId::class])->group(function (): void {
    // Health Check
    Route::get('/health', HealthController::class)->name('health');

    // Operational Metrics (v1)
    Route::get('/v1/metrics', [IncidentObservabilityController::class, 'metrics'])->name('v1.metrics');

    // Authentication Routes
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login', [AuthController::class, 'login'])->name('login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

    // Public Webhook Ingestion Routes (v1)
    Route::prefix('v1/webhooks')->group(function (): void {
        Route::post('/github', [GithubWebhookController::class, 'handle'])->name('webhooks.github');
        Route::post('/snyk', [SnykWebhookController::class, 'handle'])->name('webhooks.snyk');
        Route::post('/cve', [CveWebhookController::class, 'handle'])->name('webhooks.cve');
    });

    // Incidents Telemetry & Read APIs (v1)
    Route::prefix('v1/incidents')->name('v1.incidents.')->group(function (): void {
        Route::get('/', [IncidentController::class, 'index'])->name('index');
        Route::get('/{incident}', [IncidentTelemetryController::class, 'show'])->name('show');
        Route::get('/{incident}/agent-runs', [IncidentTelemetryController::class, 'agentRuns'])->name('agent-runs');
        Route::get('/{incident}/transitions', [IncidentTelemetryController::class, 'transitions'])->name('transitions');
        Route::get('/{incident}/timeline', [IncidentObservabilityController::class, 'timeline'])->name('timeline');
        Route::get('/{incident}/trace', [IncidentObservabilityController::class, 'trace'])->name('trace');
        Route::post('/{incident}/patches/{patch}/approve', [IncidentApprovalController::class, 'approve'])->name('approve-patch');
        Route::post('/{incident}/patches/{patch}/reject', [IncidentApprovalController::class, 'reject'])->name('reject-patch');
    });

    // Incidents Management (Protected)
    Route::middleware('auth:sanctum')->prefix('incidents')->name('api.incidents.')->group(function (): void {
        Route::get('/', [IncidentController::class, 'index'])->name('index');
        Route::post('/', [IncidentController::class, 'store'])->name('store');
        Route::get('/{incident}', [IncidentController::class, 'show'])->name('show');
        Route::post('/{incident}/patches/{patch}/approve', [IncidentApprovalController::class, 'approve'])->name('patches.approve');
        Route::post('/{incident}/patches/{patch}/reject', [IncidentApprovalController::class, 'reject'])->name('patches.reject');
    });
});
