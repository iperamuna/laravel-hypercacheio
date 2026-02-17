# Release Notes - v1.0.0

**Laravel Hyper-Cache-IO**

We are thrilled to announce the first stable release of **Laravel Hyper-Cache-IO**, a high-performance, distributed cache driver designed specifically for modern Laravel applications running in multi-server environments (FrankenPHP, Swoole, Nginx/FPM clusters).

## 🚀 Key Features

*   **SQLite WAL Backend**: Leveraging SQLite's Write-Ahead Logging for exceptional read/write speeds and reliability as a persistent storage layer.
*   **L1 In-Memory Caching**: Automatic ephemeral memory caching for the duration of a request, minimizing disk I/O.
*   **Split-Architecture**:
    *   **Primary Role**: Handles writes directly to the SQLite database.
    *   **Secondary Role**: Forwards write operations to the Primary node via a lightweight internal HTTP API, while reading from a local replica (or shared volume).
*   **Atomic Operations**: robust support for `Cache::add()`, `Cache::lock()`, and atomic counters (`increment`/`decrement`), critical for high-concurrency applications.
*   **Secure synchronization**: Internal API endpoints are secured with a configurable `api_token`.
*   **Framework Compatibility**: Fully compatible with Laravel 10.x, 11.x, and the upcoming 12.x.

## 🛠 Improvements in v1.0.0

*   **Garbage Collection**: Implemented probabilistic garbage collection (1% chance on write) to automatically prune expired cache keys, keeping the database lean.
*   **Enhanced Reliability**: Added "Primary-only" fallback logic allowing the driver to function as a standalone robust cache without needing a secondary node.
*   **Strict Typing**: Improved internal type safety and error handling.
*   **Comprehensive Testing**: Added extensive integration tests covering both Primary and Secondary roles to ensure stability.

## 📦 Installation

```bash
composer require iperamuna/laravel-hypercachio
php artisan vendor:publish --tag=hypercachio-config
```

## 🙏 Acknowledgements

Developed with ❤️ by [Indunil Peramuna](https://iperamuna.online).
