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

    // Touch -> Update Expiration
    $touched = Cache::store('hypercacheio')->touch('key1', 120);
    expect($touched)->toBeTrue();

    $touchedNonExistent = Cache::store('hypercacheio')->touch('non_existent', 120);
    expect($touchedNonExistent)->toBeFalse();

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
        '*/api/hypercacheio/cache/*' => Http::response(['success' => true], 200),
        '*/api/hypercacheio/add/*' => Http::response(['added' => true], 200),
        '*/api/hypercacheio/touch/*' => Http::response(['touched' => true], 200),
    ]);

    $store = Cache::store('hypercacheio');

    // Put -> Local write + HTTP Post
    $store->put('key_put', 'val_put', 60);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/cache/key_put') && $request->method() === 'POST';
    });

    // Get -> Local read without HTTP
    $val = $store->get('key_put');
    expect($val)->toBe('val_put');

    Http::assertNotSent(function ($request) {
        return $request->method() === 'GET' && str_contains($request->url(), '/cache/');
    });

    // Add -> HTTP Post
    $added = $store->add('key_add', 'val_add', 60);
    expect($added)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/add/key_add') && $request->method() === 'POST';
    });

    // Touch -> Local + HTTP Post
    $touched = $store->touch('key_put', 120);
    expect($touched)->toBeTrue();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/touch/key_put') && $request->method() === 'POST';
    });
});

it('serializes values correctly before sending via HTTP', function () {
    config(['hypercacheio.role' => 'secondary']);
    config(['hypercacheio.primary_url' => 'http://test-primary/api/hypercacheio']);
    config(['hypercacheio.async_requests' => false]);
    config(['cache.prefix' => '']);
    Cache::forgetDriver('hypercacheio');

    Http::fake([
        '*' => Http::response(['success' => true], 200),
    ]);

    $complexArray = ['hello' => 'world', 'nested' => [1, 2, 3]];
    Cache::store('hypercacheio')->put('complex_key', $complexArray, 60);

    Http::assertSent(function ($request) use ($complexArray) {
        return str_contains($request->url(), '/cache/complex_key') &&
               $request['value'] === serialize($complexArray);
    });
});

it('can retrieve keys matching a pattern in primary role', function () {
    config(['hypercacheio.role' => 'primary']);
    config(['hypercacheio.async_requests' => false]);
    $tempDir = __DIR__.'/temp_keys_primary';
    config(['hypercacheio.sqlite_path' => $tempDir]);
    Cache::forgetDriver('hypercacheio');

    $store = Cache::store('hypercacheio');

    $store->put('import_so_slug_1', 'val1', 60);
    $store->put('import_so_slug_2', 'val2', 60);
    $store->put('export_so_slug_1', 'val3', 60);

    $keys = Cache::store('hypercacheio')->keys('import_so_slug_*');

    expect($keys)->toHaveCount(2)
        ->toContain('import_so_slug_1')
        ->toContain('import_so_slug_2')
        ->not->toContain('export_so_slug_1');

    // Clean up
    if (file_exists($tempDir)) {
        $file = $tempDir.'/hypercacheio.sqlite';
        if (file_exists($file)) {
            @unlink($file);
        }
        @rmdir($tempDir);
    }
});

it('can retrieve keys matching a pattern in secondary role locally', function () {
    config(['hypercacheio.role' => 'secondary']);
    config(['hypercacheio.primary_url' => 'http://test-primary/api/hypercacheio']);
    config(['hypercacheio.async_requests' => false]);
    config(['cache.prefix' => '']);
    Cache::forgetDriver('hypercacheio');

    Http::fake([
        '*/api/hypercacheio/cache/*' => Http::response(['success' => true], 200),
    ]);

    $store = Cache::store('hypercacheio');
    $store->put('import_so_slug_1', 'val1', 60);
    $store->put('import_so_slug_2', 'val2', 60);
    $store->put('other_key', 'val3', 60);

    $keys = $store->keys('import_so_slug_*');

    expect($keys)->toHaveCount(2)
        ->toContain('import_so_slug_1')
        ->toContain('import_so_slug_2');

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/keys');
    });
});
