<?php

namespace Iperamuna\Hypercacheio;

use Iperamuna\Hypercacheio\Concerns\InteractsWithSqlite;

class HypercacheioService
{
    use InteractsWithSqlite;

    /**
     * Create a new service instance and initialize the SQLite connection.
     */
    public function __construct()
    {
        $directory = config('hypercacheio.sqlite_path') ?? storage_path('hypercacheio');
        $this->initSqlite($directory);
    }

    /**
     * Retrieve an item from the cache.
     */
    public function get($key)
    {
        $stmt = $this->sqlite->prepare('SELECT value, expiration FROM cache WHERE key=:key');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (! $row || ($row['expiration'] && $row['expiration'] < time())) {
            return null;
        }

        return unserialize($row['value']);
    }

    /**
     * Add an item to the cache if it doesn't exist (atomic).
     */
    public function add($key, $value, $ttl = null)
    {
        $serializedValue = serialize($value);
        $expiration = $ttl ? time() + $ttl : null;

        try {
            $stmt = $this->sqlite->prepare('INSERT INTO cache(key, value, expiration) VALUES(:key, :value, :exp)');
            $stmt->execute([':key' => $key, ':value' => $serializedValue, ':exp' => $expiration]);

            return true;
        } catch (\PDOException $e) {
            $stmt = $this->sqlite->prepare('SELECT expiration FROM cache WHERE key=:key');
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row && $row['expiration'] && $row['expiration'] < time()) {
                $stmt = $this->sqlite->prepare('UPDATE cache SET value=:value, expiration=:exp WHERE key=:key');
                $stmt->execute([':key' => $key, ':value' => $serializedValue, ':exp' => $expiration]);

                return true;
            }

            return false;
        }
    }

    /**
     * Store an item in the cache (upsert).
     */
    public function put($key, $value, $ttl = null)
    {
        $serializedValue = serialize($value);
        $expiration = ($ttl && $ttl > 0) ? time() + $ttl : null;

        $stmt = $this->sqlite->prepare('
            REPLACE INTO cache(key, value, expiration)
            VALUES(:key, :value, :exp)
        ');
        $stmt->execute([':key' => $key, ':value' => $serializedValue, ':exp' => $expiration]);

        return true;
    }

    /**
     * Remove an item from the cache.
     */
    public function forget($key)
    {
        $stmt = $this->sqlite->prepare('DELETE FROM cache WHERE key=:key');
        $stmt->execute([':key' => $key]);

        return true;
    }

    /**
     * Flush all items from the cache.
     */
    public function flush()
    {
        $this->sqlite->exec('DELETE FROM cache');

        return true;
    }

    /**
     * Attempt to acquire a lock.
     */
    public function lock($key, $owner, $ttlRaw)
    {
        $ttl = time() + (int) $ttlRaw;

        try {
            $stmt = $this->sqlite->prepare('INSERT INTO cache_locks(key, owner, expiration) VALUES(:key, :owner, :exp)');
            $stmt->execute([':key' => $key, ':owner' => $owner, ':exp' => $ttl]);

            return true;
        } catch (\PDOException $e) {
            $stmt = $this->sqlite->prepare('SELECT expiration FROM cache_locks WHERE key=:key');
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row && $row['expiration'] < time()) {
                $stmt = $this->sqlite->prepare('UPDATE cache_locks SET owner=:owner, expiration=:exp WHERE key=:key');
                $stmt->execute([':key' => $key, ':owner' => $owner, ':exp' => $ttl]);

                return true;
            }

            return false;
        }
    }

    /**
     * Release a lock.
     */
    public function releaseLock($key, $owner)
    {
        $stmt = $this->sqlite->prepare('DELETE FROM cache_locks WHERE key=:key AND owner=:owner');
        $stmt->execute([':key' => $key, ':owner' => $owner]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Ping the server.
     */
    public function ping()
    {
        return [
            'message' => 'pong',
            'role' => config('hypercacheio.role'),
            'hostname' => gethostname(),
            'time' => time(),
        ];
    }
}
