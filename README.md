# Laravel Hyper-Cache-IO

[![Latest Version on Packagist](https://img.shields.io/packagist/v/iperamuna/laravel-hypercacheio.svg?style=flat-square)](https://packagist.org/packages/iperamuna/laravel-hypercacheio)
[![Total Downloads](https://img.shields.io/packagist/dt/iperamuna/laravel-hypercacheio.svg?style=flat-square)](https://packagist.org/packages/iperamuna/laravel-hypercacheio)
[![License](https://img.shields.io/packagist/l/iperamuna/laravel-hypercacheio.svg?style=flat-square)](https://packagist.org/packages/iperamuna/laravel-hypercacheio)

**Laravel Hyper-Cache-IO** is an ultra-fast, distributed cache driver for Laravel applications. By combining **L1 in-memory caching** with a persistent **SQLite WAL backend**, it delivers exceptional performance and reliability without the overhead of Redis or Memcached.

Designed for modern PHP environments like **FrankenPHP**, **Swoole**, and traditional **Nginx/FPM**, it features a lightweight internal HTTP API for seamless multi-server synchronization.

---

## ⚡ Features

- **🚀 High Performance**: Built on SQLite WAL (Write-Ahead Logging) for lightning-fast reads and writes.
- **🧠 L1 In-Memory Cache**: Ephemeral memory caching for instant access during the request lifecycle.
- **🐹 Go Server (New)**: Optional standalone Go-based binary for high-concurrency inter-server synchronization.
- **🔄 Active-Active HA Mode**: Fully synchronized multi-node clusters using binary TCP replication.
- **🛡️ Service Management**: Built-in support for running as a systemd (Linux) or launchd (macOS) service with auto-restart.
- **🔒 Distributed Locking**: Full support for atomic locks across multiple servers.
- **⚡ Atomic Operations**: Native support for `Cache::add()` and atomic `increment`/`decrement`.
- **🌐 HTTP Synchronization**: Robust Primary/Secondary architecture for multi-node setups.
- **🛡️ Secure**: Token-based authentication protects your internal cache API.
- **✅ Modern Compatibility**: Fully supports Laravel 10.x, 11.x, and 12.x.

---

## 📦 Installation

Install the package via Composer:

```bash
composer require iperamuna/laravel-hypercacheio
```

Run the installation command to configure the package:

```bash
php artisan hypercacheio:install
```

---

## ⚙️ Configuration

### 1. Set the Cache Driver

Update your `.env` file to use `hypercacheio`:

```dotenv
CACHE_DRIVER=hypercacheio
```

### 2. Configure Server Roles

Hyper-Cache-IO uses a simple **Primary/Secondary** architecture. You can also run it in standalone mode (Primary only).

#### Primary Server (Writer)
A single "Primary" node handles all write operations to the database. You can optionally list secondary server URLs so the primary replicates writes to them.

```dotenv
HYPERCACHEIO_SERVER_ROLE=primary
HYPERCACHEIO_API_TOKEN=your-secr3t-t0ken-here

# Optional: comma-separated list of secondary server URLs for replication.
# Invalid URLs are silently ignored. Whitespace around URLs is trimmed.
HYPERCACHEIO_SECONDARY_URLS="https://s2.example.com,https://s3.example.com"

# Optional: fire-and-forget async replication (default: true)
HYPERCACHEIO_ASYNC=true
```

#### Secondary Server (Reader)
"Secondary" nodes read from their local copy (synced via shared volume or future replication features) and forward writes to the Primary via HTTP.

```dotenv
HYPERCACHEIO_SERVER_ROLE=secondary
HYPERCACHEIO_PRIMARY_URL=https://primary.example.com/api/hypercacheio
HYPERCACHEIO_API_TOKEN=your-secr3t-t0ken-here
```

### 3. Advanced Configuration

Publish and fine-tune the config file at `config/hypercacheio.php`:

```php
return [
    // Server role: 'primary' or 'secondary'
    'role' => env('HYPERCACHEIO_SERVER_ROLE', 'primary'),

    // Primary server API URL (used by secondary nodes)
    'primary_url' => env('HYPERCACHEIO_PRIMARY_URL', 'http://127.0.0.1/api/hypercacheio'),

    // Comma-separated secondary server URLs for write replication
    // Invalid URLs are silently discarded to prevent misconfiguration
    'secondaries' => HypercacheioSecondaryUrls(env('HYPERCACHEIO_SECONDARY_URLS', ''), ','),

    // Shared secret for inter-server authentication (X-HyperCacheio-Token header)
    'api_token' => env('HYPERCACHEIO_API_TOKEN', 'changeme'),

    // HTTP timeout in seconds for peer-server requests (recommended: 1–3)
    'timeout' => 1,

    // Fire-and-forget async replication (lower latency, silent failures)
    'async_requests' => env('HYPERCACHEIO_ASYNC', true),

    // SQLite database directory (auto-created by the install command)
    'sqlite_path' => storage_path('hypercacheio'),
];
```

### 4. Environment Variables Reference

| Variable | Description | Default |
| :--- | :--- | :--- |
| `CACHE_DRIVER` | Set to `hypercacheio` to use this driver | — |
| `HYPERCACHEIO_SERVER_ROLE` | `primary` or `secondary` | `primary` |
| `HYPERCACHEIO_PRIMARY_URL` | Full URL of the primary server's API | `http://127.0.0.1/api/hypercacheio` |
| `HYPERCACHEIO_SECONDARY_URLS` | Comma-separated secondary server URLs | _(empty)_ |
| `HYPERCACHEIO_API_TOKEN` | Shared secret for inter-server auth | `changeme` |
| `HYPERCACHEIO_ASYNC` | Enable fire-and-forget replication | `true` |
| `HYPERCACHEIO_SERVER_TYPE` | `laravel` (default) or `go` | `laravel` |
| `HYPERCACHEIO_GO_DIRECT_SQLITE` | Execute SQLite queries directly in Go | `true` |
| `HYPERCACHEIO_GO_HOST` | External/advertised IP of the Go server (used by secondaries) | `127.0.0.1` |
| `HYPERCACHEIO_GO_LISTEN_HOST` | IP the Go daemon **binds** to. Use `0.0.0.0` to listen on all interfaces | `0.0.0.0` |
| `HYPERCACHEIO_GO_PORT` | Port the Go server listens on | `8080` |
| `HYPERCACHEIO_HA_ENABLED` | Enable Active-Active HA Mode | `true` |
| `HYPERCACHEIO_PEER_ADDRS` | Comma-separated replication peers (IP:Port) | _(empty)_ |
| `HYPERCACHEIO_REPL_PORT` | Port for inter-node binary replication | `7400` |

---

## 🐹 Go Server Implementation (Experimental)

For high-traffic applications, you can use the standalone Go server instead of the built-in Laravel routes. This provides ultra-low latency and handles concurrent synchronization requests more efficiently.

With version **1.5.0**, the Go server now connects **directly to the SQLite database** (`storage/hypercacheio/hypercacheio.sqlite`) to process caching and locks natively using PHP serialization bindings, bypassing the `php artisan` bootstrap entirely. Response times drop from ~50ms down to **< 1ms**.

Binaries are stored in `resources/hypercacheio/bin/` and can be committed to your repository for seamless deployment without requiring a Go compiler on production servers.

### 1. Enable Go Server
Update your `.env`:
```dotenv
HYPERCACHEIO_SERVER_TYPE=go
HYPERCACHEIO_GO_DIRECT_SQLITE=true

# External IP that secondary servers use to reach this node
HYPERCACHEIO_GO_HOST=10.80.3.131
HYPERCACHEIO_GO_PORT=8185

# IP the daemon binds to — 0.0.0.0 (default) listens on all interfaces.
HYPERCACHEIO_GO_LISTEN_HOST=0.0.0.0
```

### 2. Active-Active HA Mode (TCP Replication)

As of version **1.6.0**, Hyper-Cache-IO supports a robust Active-Active HA architecture. Multiple application servers can each run their own local Go cache node, with all nodes synchronizing state in real-time over a dedicated binary TCP protocol.

- **Full-Mesh Replication**: Every write on one node is instantly broadcast to all configured peers.
- **Bootstrap Sync**: When a new node joins the cluster, it automatically requests a full state dump from existing peers.
- **Zero-Wait Primary**: No more bottlenecking on a single "Primary" URL. Your app talks to its local node, and replication happens in the background.

To enable HA Mode, configure your peers in `.env`:
```dotenv
HYPERCACHEIO_HA_ENABLED=true
HYPERCACHEIO_REPL_PORT=7400

# Comma-separated list of peer IP:REPL_PORT addresses
HYPERCACHEIO_PEER_ADDRS=10.0.0.2:7400,10.0.0.3:7400
```

> The `hypercacheio:go-server` command will automatically configure the Go binary to use your application's absolute database path (`config('hypercacheio.sqlite_path')`) and cache prefix (`config('cache.prefix')`).

### 2. Compile & Start
The package includes a full management CLI for the Go daemon:

```bash
# 🛠️ Compile the binary for your system (detects macOS/Linux, all architectures)
php artisan hypercacheio:go-server compile

# 🚀 Start the server as a background process
php artisan hypercacheio:go-server start

# 📊 Check daemon status (PID / systemd / launchd / process scan)
php artisan hypercacheio:go-server status

# 🔄 Restart the daemon
php artisan hypercacheio:go-server restart

# 🛑 Stop the daemon
php artisan hypercacheio:go-server stop
```

### 3. Run as a System Service

Generate service configuration files for your OS, then install and manage them via Artisan:

```bash
# Step 1: Generate the service file (systemd for Linux, launchd for macOS)
php artisan hypercacheio:go-server make-service

# Step 2: Install the service (one-time manual step per OS):
# Linux:  sudo cp hypercacheio-server.service /etc/systemd/system/
# macOS:  cp iperamuna.hypercacheio.server.plist ~/Library/LaunchAgents/

# Step 3: Manage via Artisan
php artisan hypercacheio:go-server service:start   # Load and start the service
php artisan hypercacheio:go-server service:stop    # Stop the service
php artisan hypercacheio:go-server service:status  # View service status (systemd/launchd output)
php artisan hypercacheio:go-server service:remove  # Disable and remove the service
```

---
## 🔌 Connectivity Check

To verify that your server can communicate with the configured Primary/Secondary nodes, run the built-in connectivity check command:

```bash
php artisan hypercacheio:connectivity-check
```

This command:
- **Identifies** server type (LARAVEL or GO) with host and port
- **Pings** the local node first via `127.0.0.1` (falls back to configured host)
- **Tests** Ping, Add, Get, Put, Delete, Lock against all configured endpoints
- **Reports** OS-specific firewall advice (ufw, firewalld, socketfilterfw) when a connection fails

---

## 🛠️ Usage

Use the standard **Laravel Cache Facade**. No new syntax to learn!

```php
use Illuminate\Support\Facades\Cache;

// ✅ Store Data
// Automatically handles L1 memory + SQLite persistence + Primary sync
Cache::put('user_preference:1', ['theme' => 'dark'], 600);

// ✅ Retrieve Data
// Checks L1 memory first, then SQLite
$prefs = Cache::get('user_preference:1');

// ✅ Atomic Addition
// Only adds if key doesn't exist (concurrency safe)
Cache::add('job_lock:123', 'processing', 60);

// ✅ Atomic Locking
// Distributed locks work across all servers
$lock = Cache::lock('processing-job', 10);

if ($lock->get()) {
    // Critical section...
    
    $lock->release();
}
```

---

## 🔌 Internal API

The package exposes a lightweight internal API for node synchronization. Each endpoint is secured via `X-Hypercacheio-Token`.

| Method | Endpoint | Description |
| :--- | :--- | :--- |
| `GET` | `/api/hypercacheio/cache/{key}` | Fetch a cached item |
| `POST` | `/api/hypercacheio/cache/{key}` | Upsert (Create/Update) an item |
| `POST` | `/api/hypercacheio/add/{key}` | Atomic "Add" operation |
| `DELETE` | `/api/hypercacheio/cache/{key}` | Remove an item |
| `POST` | `/api/hypercacheio/lock/{key}` | Acquire an atomic lock |
| `DELETE` | `/api/hypercacheio/lock/{key}` | Release an atomic lock |

---

## ✅ Testing

You can run the full test suite (Unit & Integration) using Pest:

```bash
vendor/bin/pest laravel-hypercacheio/tests
```

---

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

---

## ❤️ Credits

- Developed with ❤️ by [Indunil Peramuna](https://iperamuna.online)