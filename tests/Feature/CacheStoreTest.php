<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Iperamuna\Hypercacheio\HypercacheioStore;

afterEach(function () {
    // Clean up SQlite file
    $path = config('hypercacheio.sqlite_path') ?? storage_path('hypercacheio');
    if ($path && file_exists($path)) {
        if (is_dir($path)) {
            $file = $path.'/hypercacheio.sqlite';
            if (file_exists($file)) {
                @unlink($file);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
});

it('performs primary role operations correctly', function () {
    // Configured as primary
    config(['hypercacheio.role' => 'primary']);
    // Ensure async is false for immediate execution in tests
    config(['hypercacheio.async_requests' => false]);
    // Use a temp path for key operations
    $tempDir = __DIR__.'/temp_primary';
    config(['hypercacheio.sqlite_path' => $tempDir]);

    // Clear cache manager stores
    Cache::forgetDriver('hypercacheio');

    $store = Cache::store('hypercacheio')->getStore();
    expect($store)->toBeInstanceOf(HypercacheioStore::class);

    // Put -> Local Write
    Cache::store('hypercacheio')->put('key1', 'value1', 60);

    // no HTTP request should be sent
    Http::fake();
    Http::assertNothingSent();

    // Get -> Local Read
    $val = Cache::store('hypercacheio')->get('key1');
    expect($val)->toBe('value1');

    // Add -> Atomic Insert
    $added = Cache::store('hypercacheio')->add('key2', 'val2', 60);
    expect($added)->toBeTrue();

    $addedAgain = Cache::store('hypercacheio')->add('key2', 'val2', 60);
    expect($addedAgain)->toBeFalse();

    // Cleanup temp dir
    if (file_exists($tempDir.'/hypercacheio.sqlite')) {
        @unlink($tempDir.'/hypercacheio.sqlite');
    }
    if (file_exists($tempDir.'/hypercacheio.sqlite-wal')) {
        @unlink($tempDir.'/hypercacheio.sqlite-wal');
    }
    if (file_exists($tempDir.'/hypercacheio.sqlite-shm')) {
        @unlink($tempDir.'/hypercacheio.sqlite-shm');
    }
    if (is_dir($tempDir)) {
        rmdir($tempDir);
    }
});

it('performs secondary role operations correctly via HTTP', function () {
    // Reconfigure as secondary
    config(['hypercacheio.role' => 'secondary']);
    config(['hypercacheio.primary_url' => 'http://test-primary/api/hypercacheio']);
    config(['hypercacheio.async_requests' => false]); // Force sync
    config(['cache.prefix' => '']); // Ensure no prefix for key matching

    // Clear cache manager stores
    Cache::forgetDriver('hypercacheio');

    // Mock HTTP for Secondary
    Http::fake([
        '*/api/hypercacheio/cache/key_sec' => Http::response(json_encode('value_sec'), 200),
        '*/api/hypercacheio/cache/*' => Http::response(['success' => true], 200),
        '*/api/hypercacheio/add/*' => Http::response(['added' => true], 200),
    ]);

    $store = Cache::store('hypercacheio')->getStore();

    // Put -> HTTP Post
    Cache::store('hypercacheio')->put('key_put', 'val_put', 60);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/cache/key_put') && $request->method() === 'POST';
    });

    // Get -> HTTP Get
    $val = Cache::store('hypercacheio')->get('key_sec');
    expect($val)->toBe('value_sec');

    // Add -> HTTP Post
    $added = Cache::store('hypercacheio')->add('key_add', 'val_add', 60);
    expect($added)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/add/key_add') && $request->method() === 'POST';
    });
});
