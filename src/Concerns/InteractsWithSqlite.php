<?php

namespace Iperamuna\Hypercacheio\Concerns;

use PDO;

trait InteractsWithSqlite
{
    /**
     * The SQLite connection instance.
     *
     * @var \PDO|null
     */
    protected ?PDO $sqlite = null;

    /**
     * Initialize the SQLite connection and schema.
     *
     * @param  string  $directory
     * @return void
     */
    protected function initSqlite(string $directory): void
    {
        if (!file_exists($directory)) {
            @mkdir($directory, 0755, true);
        }

        $path = $directory . '/hypercacheio.sqlite';
        $this->sqlite = new PDO('sqlite:' . $path);
        $this->sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->sqlite->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=5000;');

        $this->createSchema();
    }

    /**
     * Create the database schema if it doesn't exist.
     *
     * @return void
     */
    protected function createSchema(): void
    {
        $this->sqlite->exec('
            CREATE TABLE IF NOT EXISTS cache(
                key TEXT PRIMARY KEY,
                value BLOB NOT NULL,
                expiration INTEGER
            );
            CREATE TABLE IF NOT EXISTS cache_locks(
                key TEXT PRIMARY KEY,
                owner TEXT NOT NULL,
                expiration INTEGER
            );
        ');
    }
}
