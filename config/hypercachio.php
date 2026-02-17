<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hypercachio Internal API Endpoint
    |--------------------------------------------------------------------------
    | The internal API URL each server exposes for cache operations.
    | Example: '/api/hypercachio' (routes handled by Laravel)
    */
    'api_url' => '/api/hypercachio',

    /*
    |--------------------------------------------------------------------------
    | Server Role
    |--------------------------------------------------------------------------
    | Defines whether this server is PRIMARY or SECONDARY.
    | - 'primary' : writes go to local SQLite
    | - 'secondary' : writes are forwarded to primary
    */
    'role' => env('HYPERCACHIO_SERVER_ROLE', 'primary'),

    /*
    |--------------------------------------------------------------------------
    | Primary Server URL
    |--------------------------------------------------------------------------
    | Required only if 'role' is secondary. All writes are forwarded here.
    | Must include HTTPS if SSL is used (e.g., 'https://server1.domain.com/api/hypercachio')
    */
    'primary_url' => env('HYPERCACHIO_PRIMARY_URL', 'http://127.0.0.1/api/hypercachio'),

    /*
    |--------------------------------------------------------------------------
    | Optional Secondary Servers
    |--------------------------------------------------------------------------
    | A list of other secondary servers for failover or replication.
    | Each secondary only needs 'url'.
    */
    'secondaries' => [
        // ['url' => 'http://server2.domain.com/api/hypercachio'],
    ],

    /*
    |--------------------------------------------------------------------------
    | SQLite Storage Directory
    |--------------------------------------------------------------------------
    | The directory where the SQLite database ('hypercachio.sqlite') and its
    | associated files (WAL, SHM) will be stored locally.
    */
    'sqlite_path' => storage_path('cache/hypercachio'),

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
    'api_token' => env('HYPERCACHIO_API_TOKEN', 'changeme'),

    /*
    |--------------------------------------------------------------------------
    | Async Requests
    |--------------------------------------------------------------------------
    | If enabled, write operations in secondary role will be fired
    | asynchronously to improve performance (fire-and-forget).
    */
    'async_requests' => env('HYPERCACHIO_ASYNC', true),

];
