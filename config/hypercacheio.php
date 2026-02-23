<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hypercacheio Internal API Endpoint
    |--------------------------------------------------------------------------
    |
    | The URI path that each server exposes for internal cache synchronisation
    | operations (get, put, forget, flush). This path is registered
    | automatically by the package's route file and protected by the
    | HyperCacheioSecurity middleware.
    |
    | You should rarely need to change this unless it conflicts with an
    | existing route in your application.
    |
    | Default: '/api/hypercacheio'
    |
    */
    'api_url' => '/api/hypercacheio',

    /*
    |--------------------------------------------------------------------------
    | Server Role
    |--------------------------------------------------------------------------
    |
    | Determines how this server participates in the cache cluster.
    |
    | Supported values:
    |   - 'primary'   → Cache reads and writes are performed against the
    |                    local SQLite store. Write operations are replicated
    |                    to any configured secondary servers.
    |   - 'secondary' → Cache reads are served locally, but all write
    |                    operations are forwarded to the primary server,
    |                    which then replicates them back.
    |
    | Env: HYPERCACHEIO_SERVER_ROLE
    |
    */
    'role' => env('HYPERCACHEIO_SERVER_ROLE', 'primary'),

    /*
    |--------------------------------------------------------------------------
    | Primary Server URL
    |--------------------------------------------------------------------------
    |
    | The full URL of the primary server's Hypercacheio API endpoint.
    | This is only used when the current server's role is 'secondary';
    | all write operations will be forwarded to this URL.
    |
    | Must include the scheme (http / https) and the API path, e.g.:
    |   'https://primary.example.com/api/hypercacheio'
    |
    | Env: HYPERCACHEIO_PRIMARY_URL
    |
    */
    'primary_url' => env('HYPERCACHEIO_PRIMARY_URL', 'http://127.0.0.1/api/hypercacheio'),

    /*
    |--------------------------------------------------------------------------
    | Secondary Server URLs
    |--------------------------------------------------------------------------
    |
    | A comma-separated list of secondary server base URLs that the primary
    | will replicate write operations to. Each URL should be a fully
    | qualified domain including scheme (http / https).
    |
    | The helper `HypercacheioSecondaryUrls()` parses the env string into
    | an array of ['url' => '...'] entries. Invalid URLs are silently
    | discarded to prevent misconfiguration.
    |
    | Example .env value:
    |   HYPERCACHEIO_SECONDARY_URLS="https://s2.example.com,https://s3.example.com"
    |
    | Env: HYPERCACHEIO_SECONDARY_URLS
    |
    */
    'secondaries' => HypercacheioSecondaryUrls(env('HYPERCACHEIO_SECONDARY_URLS', ''), ','),

    /*
    |--------------------------------------------------------------------------
    | SQLite Storage Directory
    |--------------------------------------------------------------------------
    |
    | The absolute path to the directory where the local SQLite database
    | file ('hypercacheio.sqlite') and its associated WAL / SHM journal
    | files will be stored.
    |
    | The directory will be created automatically by the install command
    | if it does not already exist. Ensure the web server process has
    | read/write permissions on this path.
    |
    | Default: storage/hypercacheio
    |
    */
    'sqlite_path' => storage_path('hypercacheio'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds to wait for a response when making HTTP
    | requests to peer servers (primary or secondaries). Keep this value
    | low to avoid blocking the request cycle; a timeout of 1–3 seconds
    | is recommended for most deployments.
    |
    | Default: 1
    |
    */
    'timeout' => 1,

    /*
    |--------------------------------------------------------------------------
    | API Token (Shared Secret)
    |--------------------------------------------------------------------------
    |
    | A shared secret token used to authenticate inter-server requests.
    | Every Hypercacheio HTTP request includes this token in the
    | 'X-HyperCacheio-Token' header, and the receiving server's
    | HyperCacheioSecurity middleware validates it.
    |
    | ⚠️  Change this to a strong, random value in production. The default
    |     'changeme' is intentionally insecure to encourage replacement.
    |
    | Env: HYPERCACHEIO_API_TOKEN
    |
    */
    'api_token' => env('HYPERCACHEIO_API_TOKEN', 'changeme'),

    /*
    |--------------------------------------------------------------------------
    | Asynchronous Replication
    |--------------------------------------------------------------------------
    |
    | When enabled, write operations that need to be forwarded to peer
    | servers (primary → secondaries, or secondary → primary) are
    | dispatched asynchronously using fire-and-forget HTTP requests.
    |
    | This significantly reduces latency on the originating request but
    | means replication failures will be silent. Disable this if you need
    | guaranteed write consistency across servers.
    |
    | Env: HYPERCACHEIO_ASYNC
    |
    */
    'async_requests' => env('HYPERCACHEIO_ASYNC', true),

    /*
    |--------------------------------------------------------------------------
    | Server Type
    |--------------------------------------------------------------------------
    |
    | Choose the server implementation for handling inter-server cache sync.
    |   - 'laravel' (default) → Uses the built-in Laravel routes and controllers.
    |   - 'go'                → Uses a standalone Go-based binary server.
    |
    | Env: HYPERCACHEIO_SERVER_TYPE
    |
    */
    'server_type' => env('HYPERCACHEIO_SERVER_TYPE', 'laravel'),

    /*
    |--------------------------------------------------------------------------
    | Go Server Configuration
    |--------------------------------------------------------------------------
    |
    | settings for the standalone Go server daemon.
    |
    */
    'go_server' => [
        /*
         * The external/advertised host IP — used by secondary servers to connect here
         * and shown in status/connectivity-check output.
         * Env: HYPERCACHEIO_GO_HOST
         */
        'host' => env('HYPERCACHEIO_GO_HOST', '127.0.0.1'),

        /*
         * The IP the Go daemon actually binds/listens on.
         * Use 0.0.0.0 to listen on all interfaces (recommended for servers with a
         * dedicated LAN IP), so the local health-check via 127.0.0.1 works.
         * Env: HYPERCACHEIO_GO_LISTEN_HOST
         */
        'listen_host' => env('HYPERCACHEIO_GO_LISTEN_HOST', '0.0.0.0'),

        'port' => env('HYPERCACHEIO_GO_PORT', '8080'),
        'ssl' => [
            'enabled' => env('HYPERCACHEIO_GO_SSL_ENABLED', false),
            'certificate' => env('HYPERCACHEIO_GO_SSL_CERT', ''),
            'certificate_key' => env('HYPERCACHEIO_GO_SSL_KEY', ''),
        ],
        'bin_path' => null, // If null, the command will try to find the correct binary in the build folder.
        'build_path' => storage_path('hypercacheio/bin'),
        'log_path' => storage_path('logs/hypercacheio-server.log'),
        'pid_path' => storage_path('hypercacheio-server.pid'),
    ],

];
