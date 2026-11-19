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
     * The secondary server configurations.
     */
    protected array $secondaries = [];

    /**
     * Unix domain socket path for local Go server communication.
     */
    protected string $unixSocketPath = '';

    /**
     * Create a new Hypercacheio store instance.
     *
     * @return void
     */
    public function __construct(array $config)
    {
        $this->setPrefix($config['prefix'] ?? '');
        $this->timeout = $config['timeout'] ?? 1;
        $this->role = $config['role'] ?? 'primary';
        $this->primaryUrl = $config['primary_url'] ?? '';
        $this->apiToken = $config['api_token'] ?? '';
        $this->async = $config['async_requests'] ?? true;
        $this->haMode = $config['go_server']['ha_mode'] ?? $config['ha_mode'] ?? false;
        $this->secondaries = $config['secondaries'] ?? [];
        $this->unixSocketPath = '';

        if ($this->haMode && ($config['server_type'] ?? 'laravel') === 'go') {
            // In HA mode with Go server, we talk to the LOCAL Go server for peer replication & locks.
            $goConfig = $config['go_server'] ?? [];
            $port = $goConfig['port'] ?? 8080;
            $this->primaryUrl = "http://127.0.0.1:{$port}/api/hypercacheio";
            $this->unixSocketPath = $goConfig['unix_socket'] ?? '';
        }

        // Always initialize local SQLite database for local in-memory/disk lookups and writes
        $directory = $config['sqlite_path'] ?? storage_path('hypercacheio');
        $this->initSqlite($directory);

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

    protected function shouldSyncToPeers(): bool
    {
        return $this->haMode || ! empty($this->secondaries) || ($this->role === 'secondary' && ! empty($this->primaryUrl)) || ! empty($this->primaryUrl);
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
            $options = [];
            if (! empty($this->unixSocketPath) && file_exists($this->unixSocketPath)) {
                $options['curl'] = [CURLOPT_UNIX_SOCKET_PATH => $this->unixSocketPath];
            }

            $promise = Http::timeout($this->timeout)
                ->withOptions($options)
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
            $options = [];
            if (! empty($this->unixSocketPath) && file_exists($this->unixSocketPath)) {
                $options['curl'] = [CURLOPT_UNIX_SOCKET_PATH => $this->unixSocketPath];
            }

            $response = Http::timeout($this->timeout)
                ->withOptions($options)
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

        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare('SELECT value, expiration FROM cache WHERE key=:key');
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (! $row || ($row['expiration'] && $row['expiration'] < time())) {
                return null;
            }

            $value = @unserialize($row['value']);
            if ($value === false && $row['value'] !== serialize(false)) {
                $value = $row['value'];
            }

            if (is_string($value)) {
                $nested = @unserialize($value);
                if ($nested !== false || $value === serialize(false)) {
                    $value = $nested;
                }
            }

            if ($value !== null) {
                $this->l1[$key] = $value;
            }

            return $value;
        }

        return null;
    }

    public function put($key, $value, $seconds = null)
    {
        $this->l1[$key] = $value;
        $expiration = $seconds ? time() + (int) $seconds : null;

        if ($this->sqlite) {
            $this->gc();

            $serialized = serialize($value);
            $stmt = $this->sqlite->prepare('
                REPLACE INTO cache(key, value, expiration)
                VALUES(:key, :value, :exp)
            ');
            $stmt->execute([':key' => $key, ':value' => $serialized, ':exp' => $expiration]);
        }

        if ($this->shouldSyncToPeers()) {
            $this->doRequest('post', "cache/{$key}", ['value' => serialize($value), 'ttl' => $seconds]);
        }

        return true;
    }

    public function touch($key, $seconds)
    {
        $expiration = $seconds ? time() + (int) $seconds : null;
        $touched = false;

        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare('UPDATE cache SET expiration=:exp WHERE key=:key');
            $stmt->execute([':key' => $key, ':exp' => $expiration]);
            $touched = $stmt->rowCount() > 0;
        }

        if ($this->shouldSyncToPeers()) {
            $this->doRequest('post', "touch/{$key}", ['ttl' => $seconds]);
        }

        return $touched;
    }

    public function add($key, $value, $seconds)
    {
        if ($this->haMode || $this->role === 'secondary') {
            $response = $this->doRequest('post', "add/{$key}", ['value' => serialize($value), 'ttl' => $seconds], true);
            $added = $response['added'] ?? false;
            if ($added) {
                $this->l1[$key] = $value;
                if ($this->sqlite) {
                    $expiration = $seconds ? time() + (int) $seconds : null;
                    $stmt = $this->sqlite->prepare('REPLACE INTO cache(key, value, expiration) VALUES(:key, :value, :exp)');
                    $stmt->execute([':key' => $key, ':value' => serialize($value), ':exp' => $expiration]);
                }
            }

            return $added;
        }

        if ($this->sqlite) {
            $expiration = $seconds ? time() + (int) $seconds : null;
            $serialized = serialize($value);
            try {
                $stmt = $this->sqlite->prepare('INSERT INTO cache(key, value, expiration) VALUES(:key, :value, :exp)');
                $stmt->execute([':key' => $key, ':value' => $serialized, ':exp' => $expiration]);
                $this->l1[$key] = $value;

                return true;
            } catch (\PDOException $e) {
                $stmt = $this->sqlite->prepare('SELECT expiration FROM cache WHERE key=:key');
                $stmt->execute([':key' => $key]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($row && $row['expiration'] && $row['expiration'] < time()) {
                    $stmt = $this->sqlite->prepare('UPDATE cache SET value=:value, expiration=:exp WHERE key=:key');
                    $stmt->execute([':key' => $key, ':value' => $serialized, ':exp' => $expiration]);
                    $this->l1[$key] = $value;

                    return true;
                }

                return false;
            }
        }

        return false;
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

        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare('DELETE FROM cache WHERE key=:key');
            $stmt->execute([':key' => $key]);
        }

        if ($this->shouldSyncToPeers()) {
            $this->doRequest('delete', "cache/{$key}");
        }

        return true;
    }

    public function flush()
    {
        $this->l1 = [];

        if ($this->sqlite) {
            $this->sqlite->exec('DELETE FROM cache');
        }

        if ($this->shouldSyncToPeers()) {
            $this->doRequest('delete', 'cache');
        }

        return true;
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

        if ($this->haMode || $this->role === 'secondary') {
            $response = $this->doRequest('post', "lock/{$key}", [
                'owner' => $owner,
                'ttl' => $seconds,
            ], true);

            return $response['acquired'] ?? false;
        }

        if ($this->sqlite) {
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
        }

        return false;
    }

    public function releaseLock($key, $owner)
    {
        if ($this->haMode || $this->role === 'secondary') {
            // Fetch the actual owner from the Go server so we can release it regardless
            // of the owner string passed by Laravel (essential for forceRelease/scheduler).
            $actualOwner = $this->getLockOwner($key);
            if (empty($actualOwner)) {
                return true;
            }

            $response = $this->doRequest('delete', "lock/{$key}", [
                'owner' => $actualOwner,
            ], true);

            return $response['released'] ?? false;
        }

        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare('DELETE FROM cache_locks WHERE key=:key');
            $stmt->execute([':key' => $key]);

            return $stmt->rowCount() > 0;
        }

        return false;
    }

    public function getLockOwner($key)
    {
        if ($this->haMode || $this->role === 'secondary') {
            $response = $this->doRequest('get', "lock/{$key}", [], true);

            return $response['owner'] ?? '';
        }

        if ($this->sqlite) {
            $stmt = $this->sqlite->prepare('SELECT owner FROM cache_locks WHERE key=:key');
            $stmt->execute([':key' => $key]);

            return $stmt->fetchColumn() ?: '';
        }

        return '';
    }

    /**
     * Get all keys matching the given pattern.
     */
    public function keys(string $pattern): array
    {
        if ($this->sqlite) {
            $sqlPattern = str_replace(['*', '?'], ['%', '_'], $pattern);
            $stmt = $this->sqlite->prepare('SELECT key FROM cache WHERE key LIKE :pattern AND (expiration IS NULL OR expiration > :time)');
            $stmt->execute([':pattern' => $sqlPattern, ':time' => time()]);

            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        return [];
    }
}
