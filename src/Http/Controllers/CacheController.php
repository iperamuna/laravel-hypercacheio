<?php

namespace Iperamuna\Hypercachio\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CacheController extends Controller
{
    /**
     * The SQLite connection instance.
     *
     * @var \PDO
     */
    protected $sqlite;

    /**
     * Create a new CacheController instance and initialize the SQLite connection.
     */
    public function __construct()
    {
        $directory = config('hypercachio.sqlite_path');
        if (! file_exists($directory)) {
            @mkdir($directory, 0755, true);
        }
        $path = $directory.'/hypercachio.sqlite';
        $this->sqlite = new \PDO('sqlite:'.$path);
        $this->sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->sqlite->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
        $this->sqlite->exec('
            CREATE TABLE IF NOT EXISTS cache(
                key TEXT PRIMARY KEY,
                value BLOB NOT NULL,
                expiration INTEGER
            );
            CREATE TABLE IF NOT EXISTS cache_locks(
                key TEXT PRIMARY KEY,
                owner TEXT NOT NULL,
                expiration INTEGER
            );
        ');
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
        if (! $row || ($row['expiration'] && $row['expiration'] < time())) {
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
        $ttl = $request->input('ttl') ? time() + $request->input('ttl') : null; // Handle null or 0 properly
        $inputTtl = $request->input('ttl');
        $expiration = ($inputTtl && $inputTtl > 0) ? time() + $inputTtl : null;

        $stmt = $this->sqlite->prepare('
            INSERT INTO cache(key, value, expiration)
            VALUES(:key, :value, :exp)
            ON CONFLICT(key) DO UPDATE SET value=excluded.value, expiration=excluded.exp
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
}
