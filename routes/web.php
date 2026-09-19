<?php

use App\Http\Controllers\IncidentWebController;
use Illuminate\Support\Facades\Route;

Route::get('/', [IncidentWebController::class, 'index'])->name('home');

Route::prefix('incidents')->name('incidents.')->group(function (): void {
    Route::get('/', [IncidentWebController::class, 'index'])->name('index');
    Route::get('/{incident}', [IncidentWebController::class, 'show'])->name('show');
    Route::get('/{incident}/approval', [IncidentWebController::class, 'approval'])->name('approval');
    Route::post('/{incident}/patches/{patch}/approve', [IncidentWebController::class, 'approve'])->name('patches.approve');
    Route::post('/{incident}/patches/{patch}/reject', [IncidentWebController::class, 'reject'])->name('patches.reject');
    Route::post('/github/sync', [IncidentWebController::class, 'syncGithub'])->name('github.sync');
});
