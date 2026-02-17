# Changelog - Laravel Hyper-Cache-IO

All notable changes to this project will be documented in this file.

## [1.1.0] - 2026-02-17

### Added
- **SQLite Directory Storage**: Changed `sqlite_path` to define a directory instead of a file. The actual database is now stored as `hypercachio.sqlite` within this folder, allowing for better organization of associated WAL and SHM files.
- **Automated .gitignore**: The `hypercachio:install` command now automatically adds the default storage directory to the project's `.gitignore`.
- **Enhanced Documentation**: Added missing doc blocks in the configuration for better developer experience.

## [1.0.2] - 2026-02-17

### Added
- **Atomic Locking**: Implemented `LockProvider` interface in `HypercachioStore` to support `Cache::lock()` natively.
- **Distributed Locks**: Added `HypercachioLock` class to handle lock acquisition and release across Primary/Secondary nodes.

## [1.0.1] - 2026-02-17

### Added
- **Install Command**: New `php artisan hypercachio:install` command to automatically configure `config/cache.php` and publish assets.

## [1.0.0] - 2026-02-17

### Added
- **Laravel 12 Support**: Fully compatible with Laravel 10.x, 11.x, and 12.x.
- **Atomic Operations**: Implemented `add()` method for atomic insertions.
- **Distributed Locking**: Robust distributed locking via SQLite backend.
- **Failover Logic**: Primary/Secondary role configuration for high availability.
- **Performance**: L1 memory caching combined with high-performance SQLite (WAL mode).
- **Security**: Token-based authentication for internal API communication.

### Changed
- **Driver Architecture**: Refactored `HypercachioStore` for better dependency injection and testing.
- **Configuration**: Simplified configuration with specific `role` and `primary_url` settings.
