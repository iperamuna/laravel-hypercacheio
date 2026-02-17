<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hypercacheio Internal API Endpoint
    |--------------------------------------------------------------------------
    | The internal API URL each server exposes for cache operations.
    | Example: '/api/hypercacheio' (routes handled by Laravel)
    */
    'api_url' => '/api/hypercacheio',

    /*
    |--------------------------------------------------------------------------
    | Server Role
    |--------------------------------------------------------------------------
    | Defines whether this server is PRIMARY or SECONDARY.
    | - 'primary' : writes go to local SQLite
    | - 'secondary' : writes are forwarded to primary
    */
    'role' => env('HYPERCACHEIO_SERVER_ROLE', 'primary'),

    /*
    |--------------------------------------------------------------------------
    | Primary Server URL
    |--------------------------------------------------------------------------
    | Required only if 'role' is secondary. All writes are forwarded here.
    | Must include HTTPS if SSL is used (e.g., 'https://server1.domain.com/api/hypercacheio')
    */
    'primary_url' => env('HYPERCACHEIO_PRIMARY_URL', 'http://127.0.0.1/api/hypercacheio'),

    /*
    |--------------------------------------------------------------------------
    | Optional Secondary Servers
    |--------------------------------------------------------------------------
    | A list of other secondary servers for failover or replication.
    | Each secondary only needs 'url'.
    */
    'secondaries' => [
        // ['url' => 'http://server2.domain.com/api/hypercacheio'],
    ],

    /*
    |--------------------------------------------------------------------------
    | SQLite Storage Directory
    |--------------------------------------------------------------------------
    | The directory where the SQLite database ('hypercacheio.sqlite') and its
    | associated files (WAL, SHM) will be stored locally.
    */
    'sqlite_path' => storage_path('cache/hypercacheio'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => 1,

    /*
    |--------------------------------------------------------------------------
    | Security: API Token
    |--------------------------------------------------------------------------
    | Shared secret between servers to validate requests.
    */
    'api_token' => env('HYPERCACHEIO_API_TOKEN', 'changeme'),

    /*
    |--------------------------------------------------------------------------
    | Async Requests
    |--------------------------------------------------------------------------
    | If enabled, write operations in secondary role will be fired
    | asynchronously to improve performance (fire-and-forget).
    */
    'async_requests' => env('HYPERCACHEIO_ASYNC', true),

];
