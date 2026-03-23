<?php

namespace Iperamuna\Hypercacheio\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Middleware to secure Hypercacheio internal API endpoints.
 */
class HyperCacheioSecurity
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->header('X-Hypercacheio-Token');
        if ($token !== config('hypercacheio.api_token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
