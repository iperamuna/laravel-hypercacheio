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
A single "Primary" node handles all write operations to the database.

```dotenv
HYPERCACHEIO_SERVER_ROLE=primary
HYPERCACHEIO_API_TOKEN=your-secr3t-t0ken-here
```

#### Secondary Server (Reader)
"Secondary" nodes read from their local copy (synced via shared volume or future replication features) and forward writes to the Primary via HTTP.

```dotenv
HYPERCACHEIO_SERVER_ROLE=secondary
HYPERCACHEIO_PRIMARY_URL=http://<primary-server-ip>/api/hypercacheio
HYPERCACHEIO_API_TOKEN=your-secr3t-t0ken-here
```

### 3. Advanced Configuration

You can fine-tune timeouts, paths, and behavior in `config/hypercacheio.php`:

```php
return [

    /*
    |--------------------------------------------------------------------------
    | Server Role
    |--------------------------------------------------------------------------
    |
    | Define the role of this server: 'primary' or 'secondary'.
    | - Primary: Handles writes internally to SQLite.
    | - Secondary: Forwards writes to the Primary via HTTP.
    |
    */
    'role' => env('HYPERCACHEIO_SERVER_ROLE', 'primary'),

    /*
    |--------------------------------------------------------------------------
    | Primary Server URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the Primary server's internal API. 
    | Required only if this server is a 'secondary' node.
    |
    */
    'primary_url' => env('HYPERCACHEIO_PRIMARY_URL', 'http://127.0.0.1/api/hypercacheio'),

    /*
    |--------------------------------------------------------------------------
    | API Security Token
    |--------------------------------------------------------------------------
    |
    | A shared secret token to secure internal API communications between nodes.
    |
    */
    'api_token' => env('HYPERCACHEIO_API_TOKEN', 'change_me_to_a_secure_token'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for internal HTTP requests.
    |
    */
    'timeout' => 2,

    /*
    |--------------------------------------------------------------------------
    | Async Requests
    |--------------------------------------------------------------------------
    |
    | If true, write operations from Secondary nodes will be fire-and-forget
    | to improved performance (non-blocking).
    |
    */
    'async_requests' => true,

    /*
    |--------------------------------------------------------------------------
    | SQLite Storage Directory
    |--------------------------------------------------------------------------
    |
    | The absolute path to the directory where the 'hypercacheio.sqlite' database 
    | and its associated files (WAL, SHM) will be stored.
    |
    */
    'sqlite_path' => storage_path('cache/hypercacheio'),

];
```

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