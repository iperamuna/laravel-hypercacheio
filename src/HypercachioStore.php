<?php

namespace Iperamuna\Hypercachio;

use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Http;

class HypercachioStore implements Store
{
    /**
     * The local L1 cache array.
     */
    protected array $l1 = [];

    /**
     * The HTTP timeout for requests.
     */
    protected int $timeout;

    /**
     * The server role (primary or secondary).
     */
    protected string $role;

    /**
     * The primary server URL.
     */
    protected string $primaryUrl;

    /**
     * The cache key prefix.
     */
    protected string $prefix = '';

    /**
     * The API token for requests.
     */
    protected string $apiToken;

    /**
     * Whether to use async requests.
     */
    protected bool $async;

    /**
     * The SQLite connection.
     */
    protected ?\PDO $sqlite = null;

    /**
     * Create a new Hypercachio store instance.
     *
     * @return void
     */
    public function __construct(array $config)
    {
        $this->timeout = $config['timeout'] ?? 1;
        $this->role = $config['role'] ?? 'primary';
        $this->primaryUrl = $config['primary_url'] ?? '';
        $this->apiToken = $config['api_token'] ?? '';
        $this->async = $config['async_requests'] ?? true;

        if ($this->role === 'primary') {
            $path = $config['sqlite_path'] ?? storage_path('cache/hypercachio.sqlite');
            $this->initSqlite($path);
        }
    }

    protected function initSqlite($path)
    {
        if (! file_exists(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
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

    protected function doRequest(string $method, string $endpoint, array $payload = [])
    {
        if ($this->async) {
            $this->asyncRequest($method, $endpoint, $payload);

            return null;
        } else {
            return $this->syncRequest($method, $endpoint, $payload);
        }
    }

    protected function asyncRequest(string $method, string $endpoint, array $payload = [])
    {
        try {
            Http::timeout($this->timeout)
                ->withHeaders([
                    'X-Hypercachio-Token' => $this->apiToken,
                    'X-Hypercachio-Server-ID' => gethostname(),
                ])
                ->async()
                ->$method("{$this->primaryUrl}/{$endpoint}", $payload);
        } catch (\Exception $e) {
            // Fail silently
        }
    }

    protected function syncRequest(string $method, string $endpoint, array $payload = [])
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-Hypercachio-Token' => $this->apiToken,
                    'X-Hypercachio-Server-ID' => gethostname(),
                ])
                ->$method("{$this->primaryUrl}/{$endpoint}", $payload);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            // Log if needed
        }

        return null;
    }

    public function get($key)
    {
        $prefixedKey = $this->prefix.$key;
        if (isset($this->l1[$prefixedKey])) {
            return $this->l1[$prefixedKey];
        }

        if ($this->role === 'primary') {
            $stmt = $this->sqlite->prepare('SELECT value, expiration FROM cache WHERE key=:key');
            $stmt->execute([':key' => $prefixedKey]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (! $row || ($row['expiration'] && $row['expiration'] < time())) {
                return null;
            }
            $value = unserialize($row['value']);
        } else {
            // Secondary: Sync Fetch
            $currentAsync = $this->async;
            $this->async = false;
            $value = $this->doRequest('get', "cache/{$prefixedKey}");
            $this->async = $currentAsync;
        }

        if ($value !== null) {
            $this->l1[$prefixedKey] = $value;
        }

        return $value;
    }

    public function put($key, $value, $seconds = null)
    {
        $prefixedKey = $this->prefix.$key;
        $this->l1[$prefixedKey] = $value;
        $expiration = $seconds ? time() + (int) $seconds : null;

        if ($this->role === 'primary') {
            // Chance to GC
            $this->gc();

            $serialized = serialize($value);
            $stmt = $this->sqlite->prepare('
                INSERT INTO cache(key, value, expiration)
                VALUES(:key, :value, :exp)
                ON CONFLICT(key) DO UPDATE SET value=excluded.value, expiration=excluded.expiration
            ');
            $stmt->execute([':key' => $prefixedKey, ':value' => $serialized, ':exp' => $expiration]);

            return true;
        } else {
            $this->doRequest('post', "cache/{$prefixedKey}", ['value' => $value, 'ttl' => $seconds]);

            return true;
        }
    }

    public function add($key, $value, $seconds)
    {
        $prefixedKey = $this->prefix.$key;
        $expiration = $seconds ? time() + (int) $seconds : null;

        if ($this->role === 'primary') {
            $serialized = serialize($value);
            try {
                $stmt = $this->sqlite->prepare('INSERT INTO cache(key, value, expiration) VALUES(:key, :value, :exp)');
                $stmt->execute([':key' => $prefixedKey, ':value' => $serialized, ':exp' => $expiration]);

                return true;
            } catch (\PDOException $e) {
                // Check if expired
                $stmt = $this->sqlite->prepare('SELECT expiration FROM cache WHERE key=:key');
                $stmt->execute([':key' => $prefixedKey]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($row && $row['expiration'] && $row['expiration'] < time()) {
                    $stmt = $this->sqlite->prepare('UPDATE cache SET value=:value, expiration=:exp WHERE key=:key');
                    $stmt->execute([':key' => $prefixedKey, ':value' => $serialized, ':exp' => $expiration]);

                    return true;
                }

                return false;
            }
        } else {
            $currentAsync = $this->async;
            $this->async = false;
            $response = $this->doRequest('post', "add/{$prefixedKey}", ['value' => $value, 'ttl' => $seconds]);
            $this->async = $currentAsync;

            return $response['added'] ?? false;
        }
    }

    public function increment($key, $value = 1)
    {
        $current = $this->get($key) ?? 0;
        $new = $current + $value;
        $this->put($key, $new, null);

        return $new;
    }

    protected function gc()
    {
        if (rand(1, 100) <= 1) { // 1% chance
            $this->sqlite->exec('DELETE FROM cache WHERE expiration < '.time());
        }
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, $value * -1);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, null);
    }

    public function forget($key)
    {
        $prefixedKey = $this->prefix.$key;
        unset($this->l1[$prefixedKey]);

        if ($this->role === 'primary') {
            $stmt = $this->sqlite->prepare('DELETE FROM cache WHERE key=:key');
            $stmt->execute([':key' => $prefixedKey]);

            return true;
        } else {
            $this->doRequest('delete', "cache/{$prefixedKey}");

            return true;
        }
    }

    public function flush()
    {
        $this->l1 = [];
        if ($this->role === 'primary') {
            $this->sqlite->exec('DELETE FROM cache');

            return true;
        } else {
            $this->doRequest('delete', 'cache');

            return true;
        }
    }

    public function getPrefix()
    {
        return $this->prefix;
    }

    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function many(array $keys)
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }
}
