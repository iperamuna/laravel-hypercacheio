<?php

namespace Iperamuna\Hypercacheio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

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

        info('🔍 Hypercacheio Connectivity & Endpoint Check');
        note("Current server role: {$role}");
        note('Server Type: '.strtoupper($serverType));
        note('Hostname: '.gethostname());

        if ($serverType === 'go') {
            $goConfig = config('hypercacheio.go_server');
            note("Go Server: {$goConfig['host']}:{$goConfig['port']} ".($goConfig['ssl']['enabled'] ? '(SSL)' : '(Non-SSL)'));
        }

        // 1. Check Local Server Status
        $localUrl = $this->getLocalUrl($serverType);
        $localResult = spin(function () use ($localUrl, $apiToken, $timeout) {
            return $this->performRequest('Local Server', $localUrl, 'GET', 'ping', [], $apiToken, $timeout);
        }, 'Checking local '.strtoupper($serverType).' server...');

        if ($localResult[3] !== '✅ OK') {
            error('❌ Local '.strtoupper($serverType)." server is not responding at: $localUrl");
            note('Reason: '.$localResult[5]);

            if ($serverType === 'go') {
                $this->showFirewallAdvice($localUrl);
            }

            return 1;
        }

        $results = [];

        if ($role === 'primary') {
            $results = $this->checkFromPrimary($apiToken, $timeout);
        } else {
            $results = $this->checkFromSecondary($apiToken, $timeout);
        }

        if (empty($results)) {
            warning('No remote servers configured to check.');

            return 0;
        }

        // Display results table
        table(
            ['Server', 'Endpoint', 'Method', 'Status', 'Time', 'Message'],
            $results,
        );

        // Summary
        $passed = collect($results)->filter(fn ($row) => $row[3] === '✅ OK')->count();
        $total = count($results);
        $failed = $total - $passed;

        $this->newLine();

        if ($failed === 0) {
            info("✅ All {$total} checks passed successfully.");
        } else {
            error("❌ {$failed} of {$total} checks failed.");

            $failedServers = collect($results)->filter(fn ($row) => $row[3] !== '✅ OK')->pluck(0)->unique();
            $this->newLine();
            warning('Potential connection issues detected for: '.$failedServers->implode(', '));
            $this->showFirewallAdvice();

            return 1;
        }

        return 0;
    }

    protected function checkFromPrimary(string $apiToken, int $timeout): array
    {
        $secondaries = config('hypercacheio.secondaries', []);

        if (empty($secondaries)) {
            warning('No secondary servers configured in hypercacheio.secondaries.');

            return [];
        }

        note('Checking '.count($secondaries).' secondary server(s)...');

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

            $checkResults = spin(
                fn () => $this->runFullCheck($label, $url, $apiToken, $timeout),
                "Checking {$label} [{$serverTypeLbl}] at {$scheme}://{$host}{$port}..."
            );

            note("✔ Checked {$label} [{$serverTypeLbl}] → {$scheme}://{$host}{$port}");

            $results = array_merge($results, $checkResults);
        }

        return $results;
    }

    protected function checkFromSecondary(string $apiToken, int $timeout): array
    {
        $primaryUrl = config('hypercacheio.primary_url', '');

        if (empty($primaryUrl)) {
            warning('No primary server URL configured in hypercacheio.primary_url.');

            return [];
        }

        $parsed = parse_url($primaryUrl);
        $host = $parsed['host'] ?? $primaryUrl;
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $scheme = $parsed['scheme'] ?? 'http';
        $serverTypeLbl = strtoupper(config('hypercacheio.server_type', 'laravel'));

        note("Checking connectivity to primary server [{$serverTypeLbl}] at {$scheme}://{$host}{$port}...");

        $results = spin(
            fn () => $this->runFullCheck('Primary', $primaryUrl, $apiToken, $timeout),
            "Checking Primary [{$serverTypeLbl}] at {$scheme}://{$host}{$port}..."
        );

        note("✔ Checked Primary [{$serverTypeLbl}] → {$scheme}://{$host}{$port}");

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

            $response = Http::timeout($timeout)
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

    protected function getLocalUrl(string $serverType): string
    {
        if ($serverType === 'laravel') {
            return url(config('hypercacheio.api_url'));
        }

        $go = config('hypercacheio.go_server');
        $scheme = $go['ssl']['enabled'] ? 'https' : 'http';

        return "{$scheme}://{$go['host']}:{$go['port']}/api/hypercacheio";
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
