<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use function Pest\Laravel\artisan;

it('runs connectivity check successfully for primary role', function () {
    // Mock config
    config(['hypercacheio.role' => 'primary']);
    config(['hypercacheio.api_token' => 'test-token']);
    config([
        'hypercacheio.secondaries' => [
            ['name' => 'Secondary 1', 'url' => 'http://secondary1.test/api/hypercacheio']
        ]
    ]);

    // Mock HTTP responses
    Http::fake([
        'secondary1.test/api/hypercacheio/ping' => Http::response(['role' => 'secondary', 'hostname' => 'sec1'], 200),
        'secondary1.test/api/hypercacheio/add/*' => Http::response(['added' => true], 200),
        'secondary1.test/api/hypercacheio/cache/*' => Http::response(['success' => true], 200), // Get/Put/Delete specific
        'secondary1.test/api/hypercacheio/lock/*' => Http::response(['acquired' => true, 'released' => true], 200),
    ]);

    // Run command
    artisan('hypercacheio:connectivity-check')
        ->assertExitCode(0);
});

it('runs connectivity check successfully for secondary role', function () {
    // Mock config
    config(['hypercacheio.role' => 'secondary']);
    config(['hypercacheio.api_token' => 'test-token']);
    config(['hypercacheio.primary_url' => 'http://primary.test/api/hypercacheio']);

    // Mock HTTP responses
    Http::fake([
        'primary.test/api/hypercacheio/ping' => Http::response(['role' => 'primary', 'hostname' => 'prim1'], 200),
        'primary.test/api/hypercacheio/*' => Http::response(['success' => true], 200), // Fallback for all
    ]);

    // Run command
    artisan('hypercacheio:connectivity-check')
        ->assertExitCode(0);
});
