# Changelog - Laravel Hyper-Cache-IO

All notable changes to this project will be documented in this file.

## [1.4.2] - 2026-02-24

### Added
- **System Service Management**: New `hypercacheio:go-server` actions for system service lifecycle management:
  - `service:start` — Loads and starts the service (launchd on macOS, systemd on Linux).
  - `service:stop` — Stops the running service.
  - `service:status` — Shows real-time service status via `launchctl list` or `systemctl status`.
  - `service:remove` — Disables, stops, and removes the service files. Prompts for confirmation.

### Improved
- **Connectivity Check**: The check messages now display server type (LARAVEL/GO), host, and port for both primary and secondary servers. Server info persists after the spinner completes.

## [1.4.1] - 2026-02-24

### Changed
- **Storage Path Simplification**: Consolidated all internal storage (SQLite database and Go binaries) from `storage/cache/hypercacheio/` to `storage/hypercacheio/` for a cleaner directory structure.
- **Go Build Pipeline**: Improved Go server compilation to use the consolidated storage path, and removed binaries from the main package to reduce bundle size and encourage environment-specific builds.

## [1.4.0] - 2026-02-23

### Added
- **High-Performance Go Server**: A standalone Go-based binary implementation of the synchronization server for ultra-low latency and higher concurrency compared to the PHP-based engine.
- **Service Management CLI**: New `php artisan hypercacheio:go-server make-service` command that automatically generates systemd (Linux) and launchd (macOS) service files with built-in auto-restart functionality.
- **Improved Go CLI**: The `hypercacheio:go-server` now supports `start`, `stop`, `restart`, `status`, `compile`, and `make-service` actions.
- **OS-Specific Auto-Installation**: The Go server management command can now detect missing Go installations and offer to install Go via Homebrew (macOS) or apt-get (Linux).
- **GitHub Workflow**: Unified CI/CD pipeline including Go 1.23 environment, multi-platform compilation checks, and parallel test execution across PHP 8.2-8.4 and Laravel 10-12.

### Improved
- **Connectivity Check Intelligence**: The `hypercacheio:connectivity-check` command now identifies server types (Laravel vs Go) and performs local health pings before remote checks.
- **Firewall Discovery**: Connectivity check now provides proactive OS-specific firewall advice (ufw, firewalld, socketfilterfw) when connection failures are detected.
- **Test Coverage**: Added three new feature test suites: `GoServerCommandTest.php`, `ServerHandlerCommandTest.php`, and expanded `ConnectivityCheckCommandTest.php`.

### Fixed
- **CLI Standard Output**: Refactored `ServerHandlerCommand` to use Symfony CLI output instead of raw `echo`, improving reliability and enabling better automated testing.

## [1.3.2] - 2026-02-17

### Improved
- **`HypercacheioSecondaryUrls` Helper**: Rewrote with proper docblocks, URL trimming, empty-entry filtering, `FILTER_VALIDATE_URL` validation, fixed `$delimeter` → `$delimiter` typo, and added explicit `string` type hint for the delimiter parameter.
- **Configuration Comments**: Enhanced all config block comments with detailed descriptions, env variable references, expected formats, and practical guidance.
- **README**: Added `HYPERCACHEIO_SECONDARY_URLS` and `HYPERCACHEIO_ASYNC` documentation to the Primary Server setup section, added a full environment variable reference table, and refreshed the Advanced Configuration code block.

### Added
- **Helper Tests**: New `HypercacheioSecondaryUrlsTest.php` Pest test suite covering null/empty input, single URL, multiple URLs, whitespace trimming, invalid URL filtering, custom delimiters, and trailing delimiter handling.

## [1.3.1] - 2026-02-17

### Fixed
- **Lock Acquisition**: Fixed an issue where lock acquisition and release on Secondary nodes failed when `async_requests` was enabled. These operations now always run synchronously to return the correct result.

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
