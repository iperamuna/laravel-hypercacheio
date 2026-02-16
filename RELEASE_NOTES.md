# Release Notes - v1.0.2

**Laravel Hyper-Cache-IO**

This patch release resolves a critical issue with atomic locking where the storage driver was missing the `LockProvider` implementation.

## 🛠 Fixes

*   **Fixed**: "Call to undefined method `HypercachioStore::lock()`" error. The main store class now correctly implements `Illuminate\Contracts\Cache\LockProvider`.
*   **Added**: Native support for `Cache::lock()` and `Cache::restoreLock()` methods.
*   **Distributed Locking**: Locks are now correctly managed via the `cache_locks` table (on Primary) or forwarded via API (from Secondary), ensuring atomic operations across distributed nodes.

## 📦 Upgrade

```bash
composer update iperamuna/laravel-hypercachio
```

This update is drop-in compatible and recommended for all users.
