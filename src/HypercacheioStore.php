<?php

namespace Iperamuna\Hypercacheio;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Http;
use Iperamuna\Hypercacheio\Concerns\InteractsWithSqlite;

class HypercacheioStore implements LockProvider, Store
{
    use InteractsWithSqlite;

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
     * The pending async request promises.
     */
    protected array $promises = [];

    /**
     * Whether HA mode is enabled.
     */
    protected bool $haMode;

    /**
     * Create a new Hypercacheio store instance.
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
        $this->haMode = $config['go_server']['ha_mode'] ?? $config['ha_mode'] ?? false;

        if ($this->haMode && ($config['server_type'] ?? 'laravel') === 'go') {
            // In HA mode with Go server, we always talk to the LOCAL Go server.
            // The Go server handles replication to the peer.
            $goConfig = $config['go_server'] ?? [];
            $port = $goConfig['port'] ?? 8080;
            $this->primaryUrl = "http://127.0.0.1:{$port}/api/hypercacheio";
            $this->role = 'secondary'; // Treat as secondary to force HTTP requests to Go server
        }

        if ($this->role === 'primary' && ! $this->haMode) {
            $directory = $config['sqlite_path'] ?? storage_path('hypercacheio');
            $this->initSqlite($directory);
        }

        // Ensure async requests complete before application exit
        if (function_exists('app')) {
            app()->terminating(function () {
                foreach ($this->promises as $promise) {
                    try {
                        $promise->wait();
                    } catch (\Throwable $e) {
                        // Suppress errors from background requests
                    }
                }
            });
        }
    }

    protected function doRequest(string $method, string $endpoint, array $payload = [], bool $forceSync = false)
    {
        if ($this->async && ! $forceSync) {
            $this->asyncRequest($method, $endpoint, $payload);

            return null;
        } else {
            return $this->syncRequest($method, $endpoint, $payload);
        }
    }

    protected function asyncRequest(string $method, string $endpoint, array $payload = [])
    {
        try {

            $promise = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-Hypercacheio-Token' => $this->apiToken,
                    'X-Hypercacheio-Server-ID' => gethostname(),
                ])
                ->async()
                ->$method("{$this->primaryUrl}/{$endpoint}", $payload);

            $this->promises[] = $promise;
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    protected function syncRequest(string $method, string $endpoint, array $payload = [])
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'X-Hypercacheio-Token' => $this->apiToken,
                    'X-Hypercacheio-Server-ID' => gethostname(),
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
        if (isset($this->l1[$key])) {
            return $this->l1[$key];
        }

        if ($this->role === 'primary' && ! $this->haMode) {
            $stmt = $this->sqlite->prepare('SELECT value, expiration FROM cache WHERE key=:key');
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (! $row || ($row['expiration'] && $row['expiration'] < time())) {
                return null;
            }
            $value = unserialize($row['value']);
        } else {
            // HA or Secondary: Sync Fetch from Go server or Primary
            $response = $this->doRequest('get', "cache/{$key}", [], true);
            $value = $response['data'] ?? null;
        }

        if ($value !== null) {
            $this->l1[$key] = $value;
        }

        return $value;
    }

    public function put($key, $value, $seconds = null)
    {
        $this->l1[$key] = $value;
        $expiration = $seconds ? time() + (int) $seconds : null;

        if ($this->role === 'primary' && ! $this->haMode) {
            // Chance to GC
            $this->gc();

            $serialized = serialize($value);
            $stmt = $this->sqlite->prepare('
                REPLACE INTO cache(key, value, expiration)
                VALUES(:key, :value, :exp)
            ');
            $stmt->execute([':key' => $key, ':value' => $serialized, ':exp' => $expiration]);

            return true;
        } else {

            $this->doRequest('post', "cache/{$key}", ['value' => $value, 'ttl' => $seconds]);

            return true;
        }
    }

    public function add($key, $value, $seconds)
    {
        $expiration = $seconds ? time() + (int) $seconds : null;

        if ($this->role === 'primary' && ! $this->haMode) {
            $serialized = serialize($value);
            try {
                $stmt = $this->sqlite->prepare('INSERT INTO cache(key, value, expiration) VALUES(:key, :value, :exp)');
                $stmt->execute([':key' => $key, ':value' => $serialized, ':exp' => $expiration]);

                return true;
            } catch (\PDOException $e) {
                // Check if expired
                $stmt = $this->sqlite->prepare('SELECT expiration FROM cache WHERE key=:key');
                $stmt->execute([':key' => $key]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($row && $row['expiration'] && $row['expiration'] < time()) {
                    $stmt = $this->sqlite->prepare('UPDATE cache SET value=:value, expiration=:exp WHERE key=:key');
                    $stmt->execute([':key' => $key, ':value' => $serialized, ':exp' => $expiration]);

                    return true;
                }

                return false;
            }
        } else {
            $response = $this->doRequest('post', "add/{$key}", ['value' => $value, 'ttl' => $seconds], true);

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
        unset($this->l1[$key]);

        if ($this->role === 'primary' && ! $this->haMode) {
            $stmt = $this->sqlite->prepare('DELETE FROM cache WHERE key=:key');
            $stmt->execute([':key' => $key]);

            return true;
        } else {
            $this->doRequest('delete', "cache/{$key}");

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

    public function lock($name, $seconds = 0, $owner = null)
    {
        return new HypercacheioLock($this, $name, $seconds, $owner);
    }

    public function restoreLock($name, $owner)
    {
        return new HypercacheioLock($this, $name, 0, $owner);
    }

    public function acquireLock($key, $owner, $seconds)
    {
        $expiration = time() + $seconds;

        if ($this->role === 'primary' && ! $this->haMode) {
            try {
                $stmt = $this->sqlite->prepare('INSERT INTO cache_locks(key, owner, expiration) VALUES(:key, :owner, :exp)');
                $stmt->execute([':key' => $key, ':owner' => $owner, ':exp' => $expiration]);

                return true;
            } catch (\PDOException $e) {
                // Check if expired
                $stmt = $this->sqlite->prepare('SELECT expiration FROM cache_locks WHERE key=:key');
                $stmt->execute([':key' => $key]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($row && $row['expiration'] < time()) {
                    $stmt = $this->sqlite->prepare('UPDATE cache_locks SET owner=:owner, expiration=:exp WHERE key=:key');
                    $stmt->execute([':key' => $key, ':owner' => $owner, ':exp' => $expiration]);

                    return true;
                }

                return false;
            }
        } else {
            // Secondary or HA
            $response = $this->doRequest('post', "lock/{$key}", [
                'owner' => $owner,
                'ttl' => $seconds,
            ], true);

            return $response['acquired'] ?? false;
        }
    }

    public function releaseLock($key, $owner)
    {
        if ($this->role === 'primary' && ! $this->haMode) {
            $stmt = $this->sqlite->prepare('DELETE FROM cache_locks WHERE key=:key AND owner=:owner');
            $stmt->execute([':key' => $key, ':owner' => $owner]);

            return $stmt->rowCount() > 0;
        } else {
            $response = $this->doRequest('delete', "lock/{$key}", [
                'owner' => $owner,
            ], true);

            return $response['released'] ?? false;
        }
    }

    public function getLockOwner($key)
    {
        if ($this->role === 'primary' && ! $this->haMode) {
            $stmt = $this->sqlite->prepare('SELECT owner FROM cache_locks WHERE key=:key');
            $stmt->execute([':key' => $key]);

            return $stmt->fetchColumn() ?: '';
        }

        return '';
    }
}
