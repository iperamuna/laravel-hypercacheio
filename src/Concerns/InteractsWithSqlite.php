<?php

namespace Iperamuna\Hypercacheio\Concerns;

use PDO;

trait InteractsWithSqlite
{
    /**
     * The SQLite connection instance.
     */
    protected ?PDO $sqlite = null;

    /**
     * Initialize the SQLite connection and schema.
     */
    protected function initSqlite(string $directory): void
    {
        if (! file_exists($directory)) {
            @mkdir($directory, 0755, true);
        }

        $path = $directory.'/hypercacheio.sqlite';
        $this->sqlite = new PDO('sqlite:'.$path);
        $this->sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->sqlite->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=5000;');

        $this->createSchema();
    }

    /**
     * Create the database schema if it doesn't exist.
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
            CREATE TABLE IF NOT EXISTS hypercacheio_queues(
                id TEXT PRIMARY KEY,
                queue_name TEXT NOT NULL,
                payload BLOB NOT NULL,
                available_at INTEGER NOT NULL,
                reserved_at INTEGER
            );
            CREATE INDEX IF NOT EXISTS idx_hypercacheio_queue_name ON hypercacheio_queues(queue_name);
            CREATE INDEX IF NOT EXISTS idx_hypercacheio_available_at ON hypercacheio_queues(available_at);
        ');
    }

    /**
     * Prune old or orphaned jobs directly from the SQLite database.
     *
     * @param  string|null  $queue
     * @param  int  $olderThanSeconds
     * @return int
     */
    public function pruneSqliteQueues(?string $queue = null, int $olderThanSeconds = 604800): int
    {
        if (! $this->sqlite) {
            return 0;
        }

        $cutoff = time() - $olderThanSeconds;
        $sql = 'DELETE FROM hypercacheio_queues WHERE available_at < :cutoff';
        $params = [':cutoff' => $cutoff];

        if ($queue !== null && $queue !== '') {
            $sql .= ' AND queue_name = :queue';
            $params[':queue'] = $queue;
        }

        $stmt = $this->sqlite->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }
}
