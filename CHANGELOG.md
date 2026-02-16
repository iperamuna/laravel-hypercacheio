# Changelog - Laravel Hyper-Cache-IO

All notable changes to this project will be documented in this file.

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
