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
