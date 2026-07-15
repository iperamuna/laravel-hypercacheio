<?php

namespace Iperamuna\Hypercacheio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class HypercacheioTopCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hypercacheio:top {--refresh=1 : Seconds to wait between refresh} {--once : Run once and exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display real-time dashboard of the Hypercacheio cluster';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $refreshRate = (int) $this->option('refresh');
        $runOnce = $this->option('once');

        if ($refreshRate < 1) {
            $refreshRate = 1;
        }

        $config = config('hypercacheio', []);
        $goConfig = $config['go_server'] ?? [];
        $port = $goConfig['port'] ?? 8080;
        $url = "http://127.0.0.1:{$port}/api/hypercacheio/ping";
        $apiToken = $config['api_token'] ?? '';
        $unixSocketPath = $goConfig['unix_socket'] ?? '';

        $options = [];
        if (! empty($unixSocketPath)) {
            $options['curl'] = [CURLOPT_UNIX_SOCKET_PATH => $unixSocketPath];
        }

        while (true) {
            try {
                $response = Http::timeout(2)
                    ->withOptions($options)
                    ->withHeaders([
                        'X-Hypercacheio-Token' => $apiToken,
                        'X-Hypercacheio-Server-ID' => gethostname(),
                    ])
                    ->get($url);

                if ($response->successful()) {
                    $this->renderDashboard($response->json());
                } else {
                    $this->error('Failed to connect to Go daemon. HTTP Status: '.$response->status());
                }
            } catch (\Exception $e) {
                $this->error('Go daemon is unreachable. Is hypercacheio-server running?');
            }

            if ($runOnce) {
                break;
            }

            sleep($refreshRate);
        }

        return 0;
    }

    protected function renderDashboard(array $data)
    {
        // Clear screen
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            system('cls');
        } else {
            system('clear');
        }

        $formatBytes = function ($bytes) {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            if ($bytes == 0) {
                return '0 B';
            }
            $i = floor(log($bytes, 1024));

            return round($bytes / pow(1024, $i), 2).' '.$units[$i];
        };

        $this->info('==========================================================');
        $this->info('   🚀 HYPERCACHEIO DASHBOARD');
        $this->info('==========================================================');

        $this->line("<fg=yellow>Server:</> {$data['hostname']} (Role: {$data['role']})");
        $this->line('<fg=yellow>Uptime (approx):</> '.date('Y-m-d H:i:s', $data['time']));
        $this->line('<fg=yellow>HA Mode:</> '.($data['ha_mode'] ? '<fg=green>Enabled</>' : '<fg=red>Disabled</>'));

        $peers = $data['peers'] ?? [];
        $this->line('<fg=yellow>Replication Peers:</> '.count($peers));
        if (! empty($peers)) {
            foreach ($peers as $peer => $isActive) {
                $status = $isActive ? '<fg=green>Online</>' : '<fg=red>Offline</>';
                $this->line("  <fg=gray>-</> $peer [$status]");
            }
        }

        $processMemory = $data['process_memory'] ?? 0;
        $heapMemory = $data['heap_memory'] ?? 0;
        $totalDisk = $data['disk_usage_total'] ?? 0;
        $cacheDisk = $data['disk_usage_cache'] ?? 0;
        $queueDisk = $data['disk_usage_queue'] ?? 0;

        $cacheBytes = $data['cache_bytes'] ?? 0;
        $lockBytes = $data['lock_bytes'] ?? 0;
        $queueBytes = $data['queue_bytes'] ?? 0;
        $totalMemoryItems = $cacheBytes + $lockBytes + $queueBytes;

        $this->line('');
        $this->info('--- 💻 MEMORY & RESOURCES ---');
        $this->line('<fg=magenta>Total Process Memory (OS):</> '.$formatBytes($processMemory));
        $this->line('<fg=magenta>Go Heap Allocated:</> '.$formatBytes($heapMemory));
        $this->line('');
        $this->line('<fg=cyan>Logical Memory (Cache+Locks):</> '.$formatBytes($cacheBytes + $lockBytes).' (Cache: '.$formatBytes($cacheBytes).', Locks: '.$formatBytes($lockBytes).')');
        $this->line('<fg=cyan>Logical Memory (Queues):</> '.$formatBytes($queueBytes));
        $this->line('<fg=cyan>Total Logical Memory (Items):</> '.$formatBytes($totalMemoryItems));

        $this->line('');
        $this->info('--- 💽 DISK USAGE (SQLite) ---');
        if ($totalDisk > 0) {
            $this->line('<fg=blue>Cache Disk Usage (est):</> '.$formatBytes($cacheDisk));
            $this->line('<fg=blue>Queue Disk Usage (est):</> '.$formatBytes($queueDisk));
            $this->line('<fg=blue>Combined Disk Usage:</> '.$formatBytes($totalDisk));
        } else {
            $this->line('<fg=gray>In-Memory Only (Persistence Disabled)</>');
        }

        $this->line('');
        $this->info('--- 📦 STORAGE & CACHE STATS ---');
        $this->line('<fg=cyan>Cache Items Count:</> '.number_format($data['items_count']));
        $this->line('<fg=cyan>Active Locks Count:</> '.number_format($data['locks_count']));

        $this->line('');
        $this->info('--- ⚙️ QUEUES ---');

        $queues = $data['queues'] ?? [];
        if (empty($queues)) {
            $this->line('<fg=gray>No queues active currently.</>');
        } else {
            $queueRows = [];
            foreach ($queues as $name => $stats) {
                $queueRows[] = [
                    $name,
                    $stats['ready'],
                    $stats['delayed'],
                    $stats['reserved'],
                    $formatBytes($stats['payload_bytes'] ?? 0),
                ];
            }
            $this->table(['Queue Name', 'Ready', 'Delayed', 'Reserved (Processing)', 'Logical Size'], $queueRows);
        }

        $this->line('');
        $this->info('--- 📈 NETWORK METRICS ---');
        $stats = $data['stats'] ?? [];
        $this->line('<fg=green>Sync Requests Served:</> '.number_format($stats['SyncRequests'] ?? 0));
        $this->line('<fg=green>Total Incoming Operations:</> '.number_format($stats['TotalReceived'] ?? 0));
        $this->line('<fg=green>Total Broadcasts to Peers:</> '.number_format($stats['TotalBroadcasts'] ?? 0));
        $this->line('<fg=gray>Press Ctrl+C to exit...</>');
    }
}
