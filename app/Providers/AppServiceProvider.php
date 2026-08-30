<?php

namespace App\Providers;

use App\Events\IncidentStatusChanged;
use App\Listeners\OrchestrateIncidentWorkflow;
use App\Services\Sandbox\DockerSandboxService;
use App\Services\Sandbox\SandboxManagerInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SandboxManagerInterface::class, DockerSandboxService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            IncidentStatusChanged::class,
            OrchestrateIncidentWorkflow::class,
        );
    }
}
