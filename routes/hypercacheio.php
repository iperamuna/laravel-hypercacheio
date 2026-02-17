<?php

use Illuminate\Support\Facades\Route;
use Iperamuna\Hypercacheio\Http\Controllers\CacheController;
use Iperamuna\Hypercacheio\Http\Middleware\HyperCacheioSecurity;

Route::prefix(config('hypercacheio.api_url'))
    ->middleware([HyperCacheioSecurity::class])
    ->name('hypercacheio.')
    ->group(function () {
        Route::get('/cache/{key}', [CacheController::class, 'get'])->name('cache.get');
        Route::post('/add/{key}', [CacheController::class, 'add'])->name('cache.add');
        Route::post('/cache/{key}', [CacheController::class, 'put'])->name('cache.put');
        Route::delete('/cache/{key}', [CacheController::class, 'forget'])->name('cache.forget');
        Route::post('/lock/{key}', [CacheController::class, 'lock'])->name('cache.lock');
        Route::delete('/lock/{key}', [CacheController::class, 'releaseLock'])->name('cache.release-lock');
        Route::get('/ping', [CacheController::class, 'ping'])->name('ping');
    });
