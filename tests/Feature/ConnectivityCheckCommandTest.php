<?php

use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

it('runs connectivity check successfully for laravel server type', function () {
    // Mock config
    config(['hypercacheio.role' => 'primary']);
    config(['hypercacheio.server_type' => 'laravel']);
    config(['hypercacheio.api_url' => '/api/hypercacheio']);
    config(['hypercacheio.api_token' => 'test-token']);
    config([
        'hypercacheio.secondaries' => [
            ['name' => 'Secondary 1', 'url' => 'http://secondary1.test/api/hypercacheio'],
        ],
    ]);

    // Local URL would be http://localhost/api/hypercacheio (mocked)
    // Secondary URL is mocked in Http::fake

    // Mock HTTP responses
    Http::fake([
        'localhost/api/hypercacheio/ping' => Http::response(['role' => 'primary', 'hostname' => 'local'], 200),
        'secondary1.test/api/hypercacheio/ping' => Http::response(['role' => 'secondary', 'hostname' => 'sec1'], 200),
        'secondary1.test/api/hypercacheio/add/*' => Http::response(['added' => true], 200),
        'secondary1.test/api/hypercacheio/cache/*' => Http::response(['success' => true], 200),
        'secondary1.test/api/hypercacheio/lock/*' => Http::response(['acquired' => true, 'released' => true], 200),
    ]);

    // Run command
    artisan('hypercacheio:connectivity-check')
        ->expectsOutputToContain('Server Type: LARAVEL')
        ->expectsOutputToContain('Checking local LARAVEL server...')
        ->assertExitCode(0);
});

it('runs connectivity check successfully for go server type', function () {
    // Mock config
    config(['hypercacheio.role' => 'primary']);
    config(['hypercacheio.server_type' => 'go']);
    config(['hypercacheio.api_token' => 'test-token']);
    config([
        'hypercacheio.go_server' => [
            'host' => '127.0.0.1',
            'port' => '8081',
            'ssl' => ['enabled' => false],
        ],
    ]);
    config([
        'hypercacheio.secondaries' => [
            ['name' => 'Secondary 1', 'url' => 'http://secondary1.test:8081/api/hypercacheio'],
        ],
    ]);

    // Mock HTTP responses
    Http::fake([
        '127.0.0.1:8081/api/hypercacheio/ping' => Http::response(['role' => 'primary', 'hostname' => 'local-go'], 200),
        'secondary1.test:8081/api/hypercacheio/ping' => Http::response(['role' => 'secondary', 'hostname' => 'sec-go'], 200),
        'secondary1.test:8081/api/hypercacheio/*' => Http::response(['success' => true, 'added' => true, 'acquired' => true, 'released' => true], 200),
    ]);

    // Run command
    artisan('hypercacheio:connectivity-check')
        ->expectsOutputToContain('Server Type: GO')
        ->expectsOutputToContain('Go Server: 127.0.0.1:8081')
        ->expectsOutputToContain('Checking local GO server...')
        ->assertExitCode(0);
});

it('fails when local server check fails and shows firewall advice', function () {
    config(['hypercacheio.server_type' => 'go']);
    config(['hypercacheio.go_server.host' => '127.0.0.1']);
    config(['hypercacheio.go_server.port' => '8081']);

    Http::fake([
        '127.0.0.1:8081/api/hypercacheio/ping' => Http::response('Error', 500),
    ]);

    artisan('hypercacheio:connectivity-check')
        ->expectsOutputToContain('Firewall & Connection Troubleshooting')
        ->expectsOutputToContain('is not responding at: http://127.0.0.1:8081/api/hypercacheio')
        ->assertExitCode(1);
});

it('bypasses local ping check when disable_local_ping_check is true', function () {
    config(['hypercacheio.role' => 'primary']);
    config(['hypercacheio.server_type' => 'go']);
    config(['hypercacheio.api_token' => 'test-token']);
    config(['hypercacheio.go_server.port' => 8081]);
    config(['hypercacheio.go_server.host' => '127.0.0.1']);
    config(['hypercacheio.go_server.ssl.enabled' => false]);
    config(['hypercacheio.go_server.disable_local_ping_check' => true]);

    // Even without an active HTTP mock or listening server, this should pass the local check phase
    // because it skips pinging. The exit code will be 0 since there are no remote servers to check.
    artisan('hypercacheio:connectivity-check')
        ->doesntExpectOutputToContain('is not responding at')
        ->assertExitCode(0);
});

it('runs connectivity check successfully for HA mode', function () {
    config(['hypercacheio.server_type' => 'go']);
    config(['hypercacheio.go_server.ha_mode' => true]);
    config(['hypercacheio.go_server.peer_addrs' => '10.0.0.2:7400,10.0.0.3:7400']);
    config(['hypercacheio.go_server.port' => 8080]);
    config(['hypercacheio.api_token' => 'test-token']);
    config(['hypercacheio.go_server.disable_local_ping_check' => true]);

    Http::fake([
        '10.0.0.2:8080/api/hypercacheio/ping' => Http::response(['role' => 'go-server-ha', 'hostname' => 'peer1'], 200),
        '10.0.0.3:8080/api/hypercacheio/ping' => Http::response(['role' => 'go-server-ha', 'hostname' => 'peer2'], 200),
        '10.0.0.2:8080/api/hypercacheio/*' => Http::response(['success' => true, 'added' => true, 'acquired' => true, 'released' => true], 200),
        '10.0.0.3:8080/api/hypercacheio/*' => Http::response(['success' => true, 'added' => true, 'acquired' => true, 'released' => true], 200),
    ]);

    artisan('hypercacheio:connectivity-check')
        ->expectsOutputToContain('Verifying HA Consistency with Peer #1 (10.0.0.2)...')
        ->expectsOutputToContain('Verifying HA Consistency with Peer #2 (10.0.0.3)...')
        ->assertExitCode(0);
});
