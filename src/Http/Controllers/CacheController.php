<?php

namespace Iperamuna\Hypercacheio\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Iperamuna\Hypercacheio\Concerns\InteractsWithSqlite;

class CacheController extends Controller
{
    use InteractsWithSqlite;
    /**
     * Create a new CacheController instance and initialize the SQLite connection.
     */
    public function __construct()
    {
        $directory = config('cache.stores.hypercacheio.sqlite_path') ?? config('hypercacheio.sqlite_path');
        $this->initSqlite($directory);
    }

    /**
     * Retrieve an item from the cache.
     *
     * @param  string  $key
     * @return \Illuminate\Http\JsonResponse
     */
    public function get($key)
    {
        $stmt = $this->sqlite->prepare('SELECT value, expiration FROM cache WHERE key=:key');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || ($row['expiration'] && $row['expiration'] < time())) {
            return response()->json(null);
        }

        return response()->json(unserialize($row['value']));
    }

    /**
     * Add an item to the cache if it doesn't exist (atomic).
     *
     * @param  string  $key
     * @return \Illuminate\Http\JsonResponse
     */
    public function add(Request $request, $key)
    {
        $value = serialize($request->input('value'));
        $ttl = $request->input('ttl') ? time() + $request->input('ttl') : null;

        try {
            // Try to insert
            $stmt = $this->sqlite->prepare('INSERT INTO cache(key, value, expiration) VALUES(:key, :value, :exp)');
            $stmt->execute([':key' => $key, ':value' => $value, ':exp' => $ttl]);

            return response()->json(['added' => true]);
        } catch (\PDOException $e) {
            // If failed, check if expired
            $stmt = $this->sqlite->prepare('SELECT expiration FROM cache WHERE key=:key');
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row && $row['expiration'] && $row['expiration'] < time()) {
                // Expired, so we can overwrite
                $stmt = $this->sqlite->prepare('UPDATE cache SET value=:value, expiration=:exp WHERE key=:key');
                $stmt->execute([':key' => $key, ':value' => $value, ':exp' => $ttl]);

                return response()->json(['added' => true]);
            }

            return response()->json(['added' => false]);
        }
    }

    /**
     * Store an item in the cache (upsert).
     *
     * @param  string  $key
     * @return \Illuminate\Http\JsonResponse
     */
    public function put(Request $request, $key)
    {
        $value = serialize($request->input('value'));
        $inputTtl = $request->input('ttl');

        $expiration = ($inputTtl && $inputTtl > 0) ? time() + $inputTtl : null;

        $stmt = $this->sqlite->prepare('
            REPLACE INTO cache(key, value, expiration)
            VALUES(:key, :value, :exp)
        ');
        $stmt->execute([':key' => $key, ':value' => $value, ':exp' => $expiration]);

        return response()->json(['success' => true]);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return \Illuminate\Http\JsonResponse
     */
    public function forget($key)
    {
        $stmt = $this->sqlite->prepare('DELETE FROM cache WHERE key=:key');
        $stmt->execute([':key' => $key]);

        return response()->json(['success' => true]);
    }

    /**
     * Attempt to acquire a lock.
     *
     * @param  string  $key
     * @return \Illuminate\Http\JsonResponse
     */
    public function lock(Request $request, $key)
    {
        $owner = $request->input('owner');
        $ttlRaw = $request->input('ttl');
        $ttl = time() + (int) $ttlRaw;

        try {
            $stmt = $this->sqlite->prepare('INSERT INTO cache_locks(key, owner, expiration) VALUES(:key, :owner, :exp)');
            $stmt->execute([':key' => $key, ':owner' => $owner, ':exp' => $ttl]);

            return response()->json(['acquired' => true]);
        } catch (\PDOException $e) {
            $stmt = $this->sqlite->prepare('SELECT expiration FROM cache_locks WHERE key=:key');
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Check if existing lock is expired
            if ($row && $row['expiration'] < time()) {
                $stmt = $this->sqlite->prepare('UPDATE cache_locks SET owner=:owner, expiration=:exp WHERE key=:key');
                $stmt->execute([':key' => $key, ':owner' => $owner, ':exp' => $ttl]);

                return response()->json(['acquired' => true]);
            }

            return response()->json(['acquired' => false]);
        }
    }

    /**
     * Release a lock.
     *
     * @param  string  $key
     * @return \Illuminate\Http\JsonResponse
     */
    public function releaseLock(Request $request, $key)
    {
        $owner = $request->input('owner');
        $stmt = $this->sqlite->prepare('DELETE FROM cache_locks WHERE key=:key AND owner=:owner');
        $stmt->execute([':key' => $key, ':owner' => $owner]);

        return response()->json(['released' => $stmt->rowCount() > 0]);
    }

    /**
     * Ping the server to check connectivity and role.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function ping()
    {
        return response()->json([
            'message' => 'pong',
            'role' => config('cache.stores.hypercacheio.role') ?? config('hypercacheio.role'),
            'hostname' => gethostname(),
            'time' => time(),
        ]);
    }
}
