<?php

use Illuminate\Support\Facades\Cache;
use Iperamuna\Hypercachio\HypercachioStore;

it('can acquire and release atomic locks', function () {
    $lock = Cache::store('hypercachio')->lock('foo', 10);

    expect($lock->get())->toBeTrue();

    // Lock is held
    $lock2 = Cache::store('hypercachio')->lock('foo', 10);
    expect($lock2->get())->toBeFalse();

    // Release
    $lock->release();

    // Can acquire again
    expect($lock2->get())->toBeTrue();
    $lock2->release();
});

it('can manipulate locks via owner', function () {
    // Get the underlying store
    $repository = Cache::store('hypercachio');
    $store = $repository->getStore();

    if (! $store instanceof HypercachioStore) {
        $this->markTestSkipped('Not using HypercachioStore');
    }

    // Manually acquire
    $owner = 'test-owner';
    $result = $store->acquireLock('bar', $owner, 10);
    expect($result)->toBeTrue();

    // Check owner
    // Note: getLockOwner applies prefix, acquireLock applied prefix.
    // So 'bar' passed to getLockOwner becomes 'prefix'.'bar'.
    // Logic inside store handles prefix consistently.
    expect($store->getLockOwner('bar'))->toBe('test-owner');

    // Release
    $result = $store->releaseLock('bar', $owner);
    expect($result)->toBeTrue();

    // Check owner gone
    // SQLite fetchColumn returns false if no row, I implemented logic to return ''
    expect($store->getLockOwner('bar'))->toBeEmpty();
});
