<?php

namespace Iperamuna\Hypercachio\Http\Middleware;

use Closure;

/**
 * Middleware to secure Hypercachio internal API endpoints.
 */
class HyperCachioSecurity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->header('X-Hypercachio-Token');
        if ($token !== config('hypercachio.api_token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
