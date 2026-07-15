<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('runs the top command successfully when the server is reachable', function () {
    // We can mock the HTTP response that the command relies on
    Http::fake([
        '*/api/hypercacheio/ping' => Http::response([
            'hostname' => 'test-host',
            'role' => 'go-server',
            'time' => time(),
            'ha_mode' => true,
            'peers' => ['127.0.0.1:8081' => true],
            'items_count' => 10,
            'locks_count' => 2,
            'cache_bytes' => 1024,
            'lock_bytes' => 256,
            'heap_memory' => 2048000,
            'process_memory' => 15000000,
            'disk_usage_total' => 5000000,
            'queues' => [
                'default' => [
                    'ready' => 5,
                    'delayed' => 0,
                    'reserved' => 1,
                    'payload_bytes' => 500
                ]
            ],
            'stats' => [
                'SyncRequests' => 10,
                'TotalReceived' => 50,
                'TotalBroadcasts' => 20,
            ],
        ], 200),
    ]);

    // Run the command with --once so it doesn't loop forever
    $exitCode = Artisan::call('hypercacheio:top', ['--once' => true]);
    
    expect($exitCode)->toBe(0);
    
    $output = Artisan::output();
    expect($output)->toContain('HYPERCACHEIO DASHBOARD');
    expect($output)->toContain('test-host');
    expect($output)->toContain('127.0.0.1:8081 [Online]');
    expect($output)->toContain('10'); // items count
    expect($output)->toContain('default'); // queue name
});

it('handles unreachable server gracefully', function () {
    Http::fake([
        '*/api/hypercacheio/ping' => Http::response(null, 500),
    ]);

    $exitCode = Artisan::call('hypercacheio:top', ['--once' => true]);
    
    expect($exitCode)->toBe(0); // Command still exits 0, just prints error
    
    $output = Artisan::output();
    expect($output)->toContain('Failed to connect to Go daemon');
});
