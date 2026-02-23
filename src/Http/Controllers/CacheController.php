<?php

namespace Iperamuna\Hypercacheio\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Iperamuna\Hypercacheio\HypercacheioService;

class CacheController extends Controller
{
    /**
     * The Hypercacheio service instance.
     */
    protected $service;

    /**
     * Create a new CacheController instance.
     */
    public function __construct(HypercacheioService $service)
    {
        $this->service = $service;
    }

    /**
     * Retrieve an item from the cache.
     */
    public function get($key)
    {
        return response()->json($this->service->get($key));
    }

    /**
     * Add an item to the cache if it doesn't exist (atomic).
     */
    public function add(Request $request, $key)
    {
        $added = $this->service->add(
            $key,
            $request->input('value'),
            $request->input('ttl')
        );

        return response()->json(['added' => $added]);
    }

    /**
     * Store an item in the cache (upsert).
     */
    public function put(Request $request, $key)
    {
        $this->service->put(
            $key,
            $request->input('value'),
            $request->input('ttl')
        );

        return response()->json(['success' => true]);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget($key)
    {
        $this->service->forget($key);

        return response()->json(['success' => true]);
    }

    /**
     * Attempt to acquire a lock.
     */
    public function lock(Request $request, $key)
    {
        $acquired = $this->service->lock(
            $key,
            $request->input('owner'),
            $request->input('ttl')
        );

        return response()->json(['acquired' => $acquired]);
    }

    /**
     * Release a lock.
     */
    public function releaseLock(Request $request, $key)
    {
        $released = $this->service->releaseLock(
            $key,
            $request->input('owner')
        );

        return response()->json(['released' => $released]);
    }

    /**
     * Ping the server to check connectivity and role.
     */
    public function ping()
    {
        return response()->json($this->service->ping());
    }
}
