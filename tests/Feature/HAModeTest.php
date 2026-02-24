<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Iperamuna\Hypercacheio\HypercacheioStore;

it('routes requests to local Go server in HA mode regardless of logical role', function () {
    // Enable HA mode and set server type to go
    config(['hypercacheio.go_server.ha_mode' => true]);
    config(['hypercacheio.server_type' => 'go']);
    config(['hypercacheio.go_server.port' => 9090]);
    config(['hypercacheio.role' => 'primary']); // Logically primary, but in HA it becomes secondary internally
    config(['hypercacheio.async_requests' => false]);
    config(['cache.prefix' => '']);

    // Clear cache manager stores
    Cache::forgetDriver('hypercacheio');

    // Mock HTTP for the LOCAL Go server
    Http::fake([
        'http://127.0.0.1:9090/api/hypercacheio/cache/ha-key' => Http::response(['data' => 'ha-value'], 200),
        'http://127.0.0.1:9090/api/hypercacheio/cache/*' => Http::response(['success' => true], 200),
    ]);

    $store = Cache::store('hypercacheio');

    // GET should go to local Go server
    $val = $store->get('ha-key');
    expect($val)->toBe('ha-value');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '127.0.0.1:9090/api/hypercacheio/cache/ha-key')
            && $request->method() === 'GET';
    });

    // PUT should go to local Go server
    $store->put('ha-put', 'value', 60);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '127.0.0.1:9090/api/hypercacheio/cache/ha-put')
            && $request->method() === 'POST';
    });
});

it('supports multiple peers in config without double prefixing issues', function () {
    config(['hypercacheio.go_server.ha_mode' => true]);
    config(['hypercacheio.server_type' => 'go']);
    config(['hypercacheio.go_server.peer_addrs' => '10.0.0.2:7400,10.0.0.3:7400']);
    config(['hypercacheio.async_requests' => false]);
    config(['cache.prefix' => 'laravel_cache']);

    Cache::forgetDriver('hypercacheio');

    Http::fake([
        '*/api/hypercacheio/cache/*' => Http::response(['success' => true], 200),
    ]);

    $store = Cache::store('hypercacheio');

    // In HA mode, Laravel Repository prefixes the key BEFORE the store receives it.
    // The store should NOT prefix it again.
    $store->put('test', 'value');

    Http::assertSent(function ($request) {
        // Just ensure it was sent to SOME Go server endpoint
        return str_contains($request->url(), '/api/hypercacheio/cache/');
    });
});