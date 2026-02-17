# Release Notes - v1.1.0

**Laravel Hyper-Cache-IO**

We are excited to announce **v1.1.0**, which brings improvements to how the SQLite backend is stored and better integration with project version control.

## 🚀 Enhancements

*   **SQLite Storage Directory**: The `sqlite_path` configuration now defines a directory. The database is stored as `hypercachio.sqlite` within this directory. This allows the associated journaling files (WAL and SHM) to be contained within a dedicated folder, keeping your storage directory clean.
*   **Automated .gitignore Integration**: The `hypercachio:install` command now automatically adds the default storage directory (`/storage/cache/hypercachio/`) to your project's `.gitignore` file.
*   **Developer Experience**: Added detailed documentation blocks to the configuration file, specifically for the `async_requests` setting.
*   **Installation Advice**: The installer now provides helpful advice regarding `.gitignore` maintenance if manual path changes are performed.

## 🛠 Internal Changes

*   **Refactored Initializer**: The `HypercachioStore` and `CacheController` now correctly initialize the storage directory and database file separately.
*   **Updated Test Suite**: Comprehensive tests added to verify directory creation and `.gitignore` automation.

## 📦 Upgrade

```bash
composer update iperamuna/laravel-hypercachio
```

> **Note**: If you are upgrading from `v1.0.x`, please update your `config/hypercachio.php` to use a directory path for `sqlite_path`, or simply run `php artisan hypercachio:install` to refresh your configuration (be sure to backup any existing configuration if needed).

## 🙏 Acknowledgements

Developed with ❤️ by [Indunil Peramuna](https://iperamuna.online).
