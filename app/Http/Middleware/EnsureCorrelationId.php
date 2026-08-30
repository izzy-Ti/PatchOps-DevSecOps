<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureCorrelationId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID');

        if (empty($correlationId)) {
            $correlationId = 'INC-'.strtoupper(Str::random(8));
            $request->headers->set('X-Correlation-ID', $correlationId);
        }

        app()->instance('correlation_id', $correlationId);

        Log::withContext([
            'correlation_id' => $correlationId,
        ]);

        $response = $next($request);

        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
