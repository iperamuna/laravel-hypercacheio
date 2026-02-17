# Changelog - Laravel Hyper-Cache-IO

All notable changes to this project will be documented in this file.

## [1.3.0] - 2026-02-17

### Added
- **Connectivity Check**: New `php artisan hypercacheio:connectivity-check` command to verify connection and operations between Primary and Secondary servers.
- **Fire-and-Forget Architecture**: Improved async request handling using Promise tracking and graceful shutdown to ensure background writes complete reliably without blocking the response.

### Changed
- **Refactoring**: Extracted SQLite logic into `InteractsWithSqlite` trait to reduce duplication between Store and Controller.
- **Configuration Alignment**: Updated `CacheController` to respect store-specific configuration (`config/cache.php`) for `sqlite_path`, aligning it with the Store implementation.
- **Testing**: Converted test suite to Pest PHP for cleaner and more expressive tests.
- **Cleanup**: Removed redundant logs and optimized code structure.

## [1.2.1] - 2026-02-17

### Added
- **Named Routes**: Added names to all internal API routes (prefix `hypercacheio.`) for easier identification and management.

## [1.2.0] - 2026-02-17

### Changed
- **Renaming**: Rebranded the package from **Hypercachio** to **Hypercacheio** throughout the entire codebase, including namespaces, class names, configuration files, and environment variables.

## [1.1.0] - 2026-02-17

### Added
- **SQLite Directory Storage**: Changed `sqlite_path` to define a directory instead of a file. The actual database is now stored as `hypercacheio.sqlite` within this folder, allowing for better organization of associated WAL and SHM files.
- **Automated .gitignore**: The `hypercacheio:install` command now automatically adds the default storage directory to the project's `.gitignore`.
- **Enhanced Documentation**: Added missing doc blocks in the configuration for better developer experience.

## [1.0.2] - 2026-02-17

### Added
- **Atomic Locking**: Implemented `LockProvider` interface in `HypercacheioStore` to support `Cache::lock()` natively.
- **Distributed Locks**: Added `HypercacheioLock` class to handle lock acquisition and release across Primary/Secondary nodes.

## [1.0.1] - 2026-02-17

### Added
- **Install Command**: New `php artisan hypercacheio:install` command to automatically configure `config/cache.php` and publish assets.

## [1.0.0] - 2026-02-17

### Added
- **Laravel 12 Support**: Fully compatible with Laravel 10.x, 11.x, and 12.x.
- **Atomic Operations**: Implemented `add()` method for atomic insertions.
- **Distributed Locking**: Robust distributed locking via SQLite backend.
- **Failover Logic**: Primary/Secondary role configuration for high availability.
- **Performance**: L1 memory caching combined with high-performance SQLite (WAL mode).
- **Security**: Token-based authentication for internal API communication.

### Changed
- **Driver Architecture**: Refactored `HypercacheioStore` for better dependency injection and testing.
- **Configuration**: Simplified configuration with specific `role` and `primary_url` settings.
