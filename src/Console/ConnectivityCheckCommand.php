<?php

namespace Iperamuna\Hypercacheio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\spin;

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
        $apiToken = config('hypercacheio.api_token', '');
        $timeout = config('hypercacheio.timeout', 1);

        info('🔍 Hypercacheio Connectivity & Endpoint Check');
        note("Current server role: {$role}");
        note('Hostname: ' . gethostname());

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
        $passed = collect($results)->filter(fn($row) => $row[3] === '✅ OK')->count();
        $total = count($results);
        $failed = $total - $passed;

        $this->newLine();

        if ($failed === 0) {
            info("✅ All {$total} checks passed successfully.");
        } else {
            error("❌ {$failed} of {$total} checks failed.");

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

        note('Checking ' . count($secondaries) . ' secondary server(s)...');

        $results = [];
        foreach ($secondaries as $index => $secondary) {
            $url = rtrim($secondary['url'] ?? '', '/');
            $label = $secondary['name'] ?? 'Secondary #' . ($index + 1);

            if (empty($url)) {
                $results[] = [$label, 'N/A', 'N/A', '❌ Failed', '-', 'No URL configured'];

                continue;
            }

            $checkResults = spin(
                fn() => $this->runFullCheck($label, $url, $apiToken, $timeout),
                "Checking {$label}..."
            );

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

        note('Checking connectivity to primary server...');

        return spin(
            fn() => $this->runFullCheck('Primary', $primaryUrl, $apiToken, $timeout),
            'Checking Primary...'
        );
    }

    protected function runFullCheck(string $label, string $baseUrl, string $apiToken, int $timeout): array
    {
        // 1. Ping
        $pingResult = $this->performRequest($label, $baseUrl, 'GET', 'ping', [], $apiToken, $timeout);

        if ($pingResult[3] !== '✅ OK') {
            return [$pingResult]; // Stop if ping fails
        }

        $results = [$pingResult];
        $testKey = 'conn-check-' . Str::random(8);

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
        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

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
            $message = $response->successful() ? '-' : "HTTP {$response->status()}: " . Str::limit($response->body(), 40);

            if ($endpoint === 'ping' && $response->successful()) {
                $json = $response->json();
                $message = 'Role: ' . ($json['role'] ?? '?') . ', Host: ' . ($json['hostname'] ?? '?');
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
}
