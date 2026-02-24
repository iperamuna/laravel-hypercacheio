# Changelog - Laravel Hyper-Cache-IO

All notable changes to this project will be documented in this file.

## [1.6.4] - 2026-02-24

### Added
- **Full Configuration Injection**: The `hypercacheio:go-server start` and `make-service` commands now pass *every* relevant configuration value (including SSL settings, HA mode toggles, and replication ports) as command-line arguments. This makes the Go binary completely dynamic and environment-independent.
- **HA-Aware Binary**: Added `--ha-mode` flag to the Go server to explicitly toggle replication listeners and peer synchronization logic.

## [1.6.3] - 2026-02-24

### Added
- **Dynamic Configuration**: The Go binary now supports environment variable fallbacks for all key flags (`HYPERCACHEIO_API_TOKEN`, `HYPERCACHEIO_SQLITE_PATH`, `HYPERCACHEIO_CACHE_PREFIX`, etc.).
- **Improved Flag Passing**: The `hypercacheio:go-server start` and `make-service` commands now explicitly pass the `--prefix` and `--sqlite-path` config values to ensure the binary reflects any runtime `.env` changes without requiring re-compilation.

## [1.6.2] - 2026-02-24

### Fixed
- **Code Style**: Applied Laravel Pint formatting across the entire codebase to ensure PSR-12 and Laravel standard compliance.

## [1.6.1] - 2026-02-24

### Fixed
- **Test Infrastructure**: Fixed a path issue in `GoServerCommandTest.php` that caused CI failures on Linux runners by using a more consistent temporary binary path.

## [1.6.0] - 2026-02-24

### Added
- **Active-Active HA Mode**: Introduced a major architectural upgrade allowing multiple Go cache nodes to synchronize state in real-time.
- **Binary TCP Replication**: Go servers now use a custom binary protocol over TCP for ultra-low latency replication (SET, DEL, FLUSH operations).
- **Multi-Peer Support**: Replaced single peer configuration with `HYPERCACHEIO_PEER_ADDRS` to support full-mesh clusters of 2 or more nodes.
- **Bootstrap Synchronization**: New nodes now automatically request a full state dump from existing peers upon connection.
- **Atomic HA Locks**: Re-engineered distributed locking with `sync.RWMutex` to ensure full atomicity in HA/cluster mode.
- **HA Connectivity Check**: The `hypercacheio:connectivity-check` command now supports HA mode, verifying all peers in the cluster.

### Fixed
- **Double Prefixing**: Removed redundant internal prefixing in `HypercacheioStore.php`. Keys are now stored in Go exactly as Laravel's Repository provides them.
- **Config Inheritance**: Improved merging of global `hypercacheio.php` settings with store-specific overrides in `config/cache.php`.
- **Lock Owner Identity**: Fixed a bug where lock owners were not correctly compared in HA mode.

## [1.5.1] - 2026-02-24

### Added
- **Direct SQLite Toggle**: Added `HYPERCACHEIO_GO_DIRECT_SQLITE` configuration to allow switching between high-performance native SQLite mode and the legacy Artisan relay mode.

### Changed
- **Binary Storage Location**: Moved default Go build output from `storage/hypercacheio/bin` to `resources/hypercacheio/bin`. This allows pre-compiled binaries to be committed to the repository for easier deployment.
- **Pint Formatting**: Applied Laravel Pint coding standards across the PHP codebase.

## [1.5.0] - 2026-02-24

### Added
- **Direct SQLite Execution for Go Server**: The standalone Go server (`hypercacheio-server`) now includes native SQLite and PHP Serialization capabilities. By removing the overhead of booting the Laravel framework via `php artisan` relay commands, cache response times drop from ~50ms to < 1ms. 
- **Command Update**: The Go Server CLI commands (`hypercacheio:go-server start` and `make-service`) now automatically supply the SQLite database path (`--sqlite-path`) and cache prefix (`--prefix`) directly to the Go daemon.

## [1.4.5] - 2026-02-24

### Added
- **`listen_host` config key** (`HYPERCACHEIO_GO_LISTEN_HOST`, defaults to `0.0.0.0`): The Go daemon now binds to `listen_host` while `host` remains the external/advertised IP used by secondaries. This fixes connectivity check failures on cloud servers where the LAN IP cannot be reached from the same host.

### Changed
- `hypercacheio:go-server start` and `make-service` now pass `listen_host` as `--host` to the binary instead of the advertised `host`.

### Upgrade Note
Regenerate and reinstall your service file to apply the new binding:
```bash
php artisan hypercacheio:go-server make-service
sudo cp hypercacheio-server.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl restart hypercacheio-server
```

## [1.4.4] - 2026-02-24

### Fixed
- **Connectivity Check local ping**: Now tries `127.0.0.1` first (fast-fail on ECONNREFUSED), then falls back to the configured host. Handles both servers bound to all interfaces and servers bound to a specific LAN/external IP.

## [1.4.3] - 2026-02-24

### Fixed
- **`go-server status`**: Now uses a 3-tier detection strategy:
  1. PID file (artisan-managed process)
  2. `systemctl is-active` / `launchctl list` (system-service-managed process)
  3. `pgrep -a hypercacheio-server` (process scan fallback)
  Previously the command always reported "NOT running" when the service was started via systemd.

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
