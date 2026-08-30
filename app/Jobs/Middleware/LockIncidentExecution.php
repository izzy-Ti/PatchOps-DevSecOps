<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LockIncidentExecution
{
    /**
     * Process the queued job wrapped in an atomic incident lock.
     *
     * @param  mixed  $job
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (! isset($job->incident)) {
            return $next($job);
        }

        $incident = $job->incident;
        $lockKey = "incident:lock:{$incident->id}";
        $ttl = (int) config('patchops.locks.incident_ttl_seconds', 300);
        $wait = (int) config('patchops.locks.incident_wait_seconds', 5);

        $lock = Cache::lock($lockKey, $ttl);

        try {
            $acquired = $wait > 0 ? $lock->block($wait) : $lock->get();

            if (! $acquired) {
                Log::warning("Incident [{$incident->incident_number} / {$incident->id}] is currently locked by another worker.", [
                    'incident_id' => $incident->id,
                    'job' => get_class($job),
                    'correlation_id' => $incident->correlation_id,
                ]);

                if (method_exists($job, 'release')) {
                    $job->release(10);
                }

                return null;
            }

            return $next($job);
        } finally {
            optional($lock)->release();
        }
    }
}
