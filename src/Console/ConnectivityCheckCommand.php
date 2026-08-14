<?php

namespace Iperamuna\Hypercacheio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ConnectivityCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hypercacheio:connectivity-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check connectivity between primary and secondary Hypercacheio servers, including all endpoints.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $role = config('hypercacheio.role', 'primary');
        $serverType = config('hypercacheio.server_type', 'laravel');
        $apiToken = config('hypercacheio.api_token', '');
        $timeout = config('hypercacheio.timeout', 1);

        $this->info('🔍 Hypercacheio Connectivity & Endpoint Check');
        $this->comment("Current server role: {$role}");
        $this->comment('Server Type: '.strtoupper($serverType));
        $this->comment('Hostname: '.gethostname());

        if ($serverType === 'go') {
            $goConfig = config('hypercacheio.go_server');
            $this->comment("Go Server: {$goConfig['host']}:{$goConfig['port']} ".($goConfig['ssl']['enabled'] ? '(SSL)' : '(Non-SSL)'));
        }

        // 1. Check Local Server Status
        // For Go server, loopback ping might be blocked by UFW.
        if (config('hypercacheio.go_server.disable_local_ping_check')) {
            $localResult = ['Local Server', '/ping', 'GET', '✅ OK (Skipped)', '-', 'Ping check bypassed via config'];
        } else {
            $localUrls = $this->getLocalUrls($serverType);
            $localResult = null;
            $localUrl = $localUrls[0];

            $localTimeout = max(3, $timeout); // Give local server at least 3s to boot/respond
            $this->output->write('Checking local '.strtoupper($serverType).' server... ');
            foreach ($localUrls as $url) {
                $result = $this->performRequest('Local Server', $url, 'GET', 'ping', [], $apiToken, $localTimeout);
                if ($result[3] === '✅ OK') {
                    $localUrl = $url;
                    $localResult = $result;
                    break;
                }
                $localUrl = $url;
            }

            if (! $localResult) {
                $localResult = $result ?? $this->performRequest('Local Server', $localUrl, 'GET', 'ping', [], $apiToken, $localTimeout);
            }

            $this->output->writeln($localResult[3]);
        }

        if ($localResult[3] !== '✅ OK' && $localResult[3] !== '✅ OK (Skipped)') {
            $this->error('❌ Local '.strtoupper($serverType)." server is not responding at: $localUrl");
            $this->comment('Reason: '.$localResult[5]);

            if ($serverType === 'go') {
                $this->showFirewallAdvice($localUrl);
            }

            return 1;
        }

        $results = [];

        if (config('hypercacheio.go_server.ha_mode')) {
            $results = $this->checkHAMode($apiToken, $timeout);
        } elseif ($role === 'primary') {
            $results = $this->checkFromPrimary($apiToken, $timeout);
        } else {
            $results = $this->checkFromSecondary($apiToken, $timeout);
        }

        if (empty($results)) {
            $this->warn('No remote servers configured to check.');

            return 0;
        }

        // Display results table
        $this->table(
            ['Server', 'Endpoint', 'Method', 'Status', 'Time', 'Message'],
            $results,
        );

        // Summary
        $passed = collect($results)->filter(fn ($row) => $row[3] === '✅ OK')->count();
        $total = count($results);
        $failed = $total - $passed;

        $this->newLine();

        if ($failed === 0) {
            $this->info("✅ All {$total} checks passed successfully.");
        } else {
            $this->error("❌ {$failed} of {$total} checks failed.");

            $failedServers = collect($results)->filter(fn ($row) => $row[3] !== '✅ OK')->pluck(0)->unique();
            $this->newLine();
            $this->warn('Potential connection issues detected for: '.$failedServers->implode(', '));
            $this->showFirewallAdvice();

            return 1;
        }

        return 0;
    }

    protected function checkFromPrimary(string $apiToken, int $timeout): array
    {
        $secondaries = config('hypercacheio.secondaries', []);

        if (empty($secondaries)) {
            $this->warn('No secondary servers configured in hypercacheio.secondaries.');

            return [];
        }

        $this->comment('Checking '.count($secondaries).' secondary server(s)...');

        $results = [];
        foreach ($secondaries as $index => $secondary) {
            $url = rtrim($secondary['url'] ?? '', '/');
            $label = $secondary['name'] ?? 'Secondary #'.($index + 1);

            if (empty($url)) {
                $results[] = [$label, 'N/A', 'N/A', '❌ Failed', '-', 'No URL configured'];

                continue;
            }

            $parsed = parse_url($url);
            $host = $parsed['host'] ?? $url;
            $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
            $scheme = $parsed['scheme'] ?? 'http';
            $serverTypeLbl = strtoupper(config('hypercacheio.server_type', 'laravel'));

            $this->output->write("Checking {$label} [{$serverTypeLbl}] at {$scheme}://{$host}{$port}... ");
            $checkResults = $this->runFullCheck($label, $url, $apiToken, $timeout);
            $this->output->writeln('Done');

            $this->comment("✔ Checked {$label} [{$serverTypeLbl}] → {$scheme}://{$host}{$port}");

            $results = array_merge($results, $checkResults);
        }

        return $results;
    }

    protected function checkFromSecondary(string $apiToken, int $timeout): array
    {
        $primaryUrl = config('hypercacheio.primary_url', '');

        if (empty($primaryUrl)) {
            $this->warn('No primary server URL configured in hypercacheio.primary_url.');

            return [];
        }

        $parsed = parse_url($primaryUrl);
        $host = $parsed['host'] ?? $primaryUrl;
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $scheme = $parsed['scheme'] ?? 'http';
        $serverTypeLbl = strtoupper(config('hypercacheio.server_type', 'laravel'));

        $this->comment("Checking connectivity to primary server [{$serverTypeLbl}] at {$scheme}://{$host}{$port}...");

        $this->output->write("Checking Primary [{$serverTypeLbl}] at {$scheme}://{$host}{$port}... ");
        $results = $this->runFullCheck('Primary', $primaryUrl, $apiToken, $timeout);
        $this->output->writeln('Done');

        $this->comment("✔ Checked Primary [{$serverTypeLbl}] → {$scheme}://{$host}{$port}");

        return $results;
    }

    protected function checkHAMode(string $apiToken, int $timeout): array
    {
        $peerAddrsStr = config('hypercacheio.go_server.peer_addrs', '');
        if (empty($peerAddrsStr)) {
            $this->warn('HA Mode enabled but no peer addresses configured in hypercacheio.go_server.peer_addrs.');

            return [];
        }

        $peerAddrs = explode(',', $peerAddrsStr);
        $results = [];

        foreach ($peerAddrs as $index => $peer) {
            $peer = trim($peer);
            if (empty($peer)) {
                continue;
            }

            // Peer format is likely IP:7400 (replication port)
            // We assume HTTP API is on the same host but using the configured HTTP port
            $host = parse_url('tcp://'.$peer, PHP_URL_HOST) ?: explode(':', $peer)[0];
            $httpPort = config('hypercacheio.go_server.port', 8080);
            $ssl = config('hypercacheio.go_server.ssl.enabled', false);
            $scheme = $ssl ? 'https' : 'http';
            $url = "{$scheme}://{$host}:{$httpPort}/api/hypercacheio";
            $label = 'Peer #'.($index + 1)." ({$host})";

            $this->comment("Checking Peer [GO] at {$url}...");

            $this->output->write("Verifying HA Consistency with {$label}... ");
            $checkResults = $this->runFullCheck($label, $url, $apiToken, $timeout);
            $this->output->writeln('Done');

            $results = array_merge($results, $checkResults);
        }

        return $results;
    }

    protected function runFullCheck(string $label, string $baseUrl, string $apiToken, int $timeout): array
    {
        // 1. Ping
        $pingResult = $this->performRequest($label, $baseUrl, 'GET', 'ping', [], $apiToken, $timeout);

        if ($pingResult[3] !== '✅ OK') {
            return [$pingResult]; // Stop if ping fails
        }

        $results = [$pingResult];
        $testKey = 'conn-check-'.Str::random(8);

        // 2. ADD (POST /add/{key})
        $results[] = $this->performRequest($label, $baseUrl, 'POST', "add/{$testKey}", [
            'value' => 'test-value',
            'ttl' => 60,
        ], $apiToken, $timeout);

        // 3. GET (GET /cache/{key})
        $results[] = $this->performRequest($label, $baseUrl, 'GET', "cache/{$testKey}", [], $apiToken, $timeout);

        // 4. PUT (POST /cache/{key})
        $results[] = $this->performRequest($label, $baseUrl, 'POST', "cache/{$testKey}", [
            'value' => 'updated-value',
            'ttl' => 60,
        ], $apiToken, $timeout);

        // 5. DELETE (DELETE /cache/{key})
        $results[] = $this->performRequest($label, $baseUrl, 'DELETE', "cache/{$testKey}", [], $apiToken, $timeout);

        // 6. LOCK (POST /lock/{key})
        $results[] = $this->performRequest($label, $baseUrl, 'POST', "lock/{$testKey}", [
            'owner' => 'connectivity-check',
            'ttl' => 10,
        ], $apiToken, $timeout);

        // 7. RELEASE LOCK (DELETE /lock/{key})
        $results[] = $this->performRequest($label, $baseUrl, 'DELETE', "lock/{$testKey}", [
            'owner' => 'connectivity-check',
        ], $apiToken, $timeout);

        return $results;
    }

    protected function performRequest(string $label, string $baseUrl, string $method, string $endpoint, array $data, string $apiToken, int $timeout): array
    {
        $url = rtrim($baseUrl, '/').'/'.ltrim($endpoint, '/');

        try {
            $start = microtime(true);

            // Using Guzzle options directly provides better handling for cURL timeouts (error 28)
            $response = Http::withOptions([
                'connect_timeout' => max(1, $timeout),
                'timeout' => max(2, $timeout + 1), // Allow an extra second for transfer
            ])
                ->withHeaders([
                    'X-Hypercacheio-Token' => $apiToken,
                    'X-Hypercacheio-Server-ID' => gethostname(),
                ])
                ->$method($url, $data);

            $elapsed = round((microtime(true) - $start) * 1000, 1);

            $status = $response->successful() ? '✅ OK' : '❌ Failed';
            $message = $response->successful() ? '-' : "HTTP {$response->status()}: ".Str::limit($response->body(), 40);

            if ($endpoint === 'ping' && $response->successful()) {
                $json = $response->json();
                $message = 'Role: '.($json['role'] ?? '?').', Host: '.($json['hostname'] ?? '?');
            }

            return [
                $label,
                "/$endpoint",
                $method,
                $status,
                "{$elapsed}ms",
                $message,
            ];
        } catch (\Exception $e) {
            return [
                $label,
                "/$endpoint",
                $method,
                '❌ Error',
                '-',
                Str::limit($e->getMessage(), 30),
            ];
        }
    }

    protected function getLocalUrls(string $serverType): array
    {
        if ($serverType === 'laravel') {
            return [url(config('hypercacheio.api_url'))];
        }

        $go = config('hypercacheio.go_server');
        $scheme = $go['ssl']['enabled'] ? 'https' : 'http';
        $port = $go['port'];
        $host = $go['host'];

        $urls = ["{$scheme}://127.0.0.1:{$port}/api/hypercacheio"];

        // Add configured host as fallback only if it differs from loopback
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'])) {
            $urls[] = "{$scheme}://{$host}:{$port}/api/hypercacheio";
        }

        return $urls;
    }

    protected function showFirewallAdvice(?string $targetUrl = null): void
    {
        $port = config('hypercacheio.go_server.port', '8080');

        // If targetUrl is provided, try to extract port from it
        if ($targetUrl && $parsed = parse_url($targetUrl)) {
            $port = $parsed['port'] ?? $port;
        }

        $this->newLine();
        $this->comment('🛡️  Firewall & Connection Troubleshooting:');
        $this->line("It seems like something is blocking the connection on port <info>{$port}</info>.");

        $os = strtolower(PHP_OS_FAMILY);

        if ($os === 'darwin') {
            $this->line("- <options=bold>macOS</>: Ensure the Go server process is allowed in 'System Settings > Network > Firewall'.");
            $this->line('  You can also try: <info>sudo /usr/libexec/ApplicationFirewall/socketfilterfw --add $(which go)</info>');
        } else {
            // Assume Linux/Unix if not macOS
            $this->line("- <options=bold>Ubuntu/Debian (ufw)</>: <info>sudo ufw allow {$port}/tcp</info>");
            $this->line("- <options=bold>CentOS/RHEL (firewalld)</>: <info>sudo firewall-cmd --permanent --add-port={$port}/tcp && sudo firewall-cmd --reload</info>");
            $this->line("- <options=bold>Cloud Environment</>: Ensure your Security Group (AWS/GCP/Azure) allows inbound TCP on port <info>{$port}</info>.");
        }

        $this->line('- <options=bold>Note</>: If you are using Go server, ensure it is actually running: <info>php artisan hypercacheio:go-server status</info>');
        $this->newLine();
    }
}
