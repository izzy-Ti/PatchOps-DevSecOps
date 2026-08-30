<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\Webhooks\CveWebhookController;
use App\Http\Controllers\Api\Webhooks\GithubWebhookController;
use App\Http\Controllers\Api\Webhooks\SnykWebhookController;
use App\Http\Middleware\EnsureCorrelationId;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureCorrelationId::class])->group(function (): void {
    // Health Check
    Route::get('/health', HealthController::class)->name('health');

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

    // Incidents Management (Protected)
    Route::middleware('auth:sanctum')->prefix('incidents')->name('incidents.')->group(function (): void {
        Route::get('/', [IncidentController::class, 'index'])->name('index');
        Route::post('/', [IncidentController::class, 'store'])->name('store');
        Route::get('/{id}', [IncidentController::class, 'show'])->name('show');
    });
});
