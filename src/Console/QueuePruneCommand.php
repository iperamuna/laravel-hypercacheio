<?php

namespace Iperamuna\Hypercacheio\Console;

use Illuminate\Console\Command;
use Iperamuna\Hypercacheio\Concerns\InteractsWithSqlite;
use PDO;

class QueuePruneCommand extends Command
{
    use InteractsWithSqlite;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hypercacheio:queue-prune
                            {--queue= : The specific queue name to prune}
                            {--days=7 : Prune jobs older than this many days}
                            {--hours= : Prune jobs older than this many hours (overrides days)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old or orphaned jobs from the Hypercacheio queue';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $queue = $this->option('queue') ?: null;
        $hours = $this->option('hours');
        $days = (int) $this->option('days');

        $seconds = $hours !== null ? ((int) $hours) * 3600 : $days * 86400;

        $this->info("Pruning jobs older than {$seconds} seconds (".($hours !== null ? "{$hours} hours" : "{$days} days").")...");

        $prunedApi = 0;
        try {
            $queueConnection = app('queue')->connection('hypercacheio');
            if (method_exists($queueConnection, 'prune')) {
                $prunedApi = $queueConnection->prune($queue, $seconds);
            }
        } catch (\Throwable $e) {
            $this->warn("Go server prune call failed or unavailable: {$e->getMessage()}");
        }

        // Direct SQLite cleanup to guarantee persistence consistency
        $prunedSqlite = 0;
        $sqliteDir = config('hypercacheio.sqlite_path', storage_path('hypercacheio'));
        $sqliteFile = rtrim((string) $sqliteDir, '/').'/hypercacheio.sqlite';

        if (file_exists($sqliteFile)) {
            try {
                $this->initSqlite($sqliteDir);
                $prunedSqlite = $this->pruneSqliteQueues($queue, $seconds);
            } catch (\Throwable $e) {
                $this->warn("Direct SQLite prune failed: {$e->getMessage()}");
            }
        }

        $totalPruned = max($prunedApi, $prunedSqlite);
        $this->info("Successfully pruned {$totalPruned} orphaned queue job(s).");

        return 0;
    }
}

