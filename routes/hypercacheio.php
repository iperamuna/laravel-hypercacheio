<?php

use Illuminate\Support\Facades\Route;
use Iperamuna\Hypercacheio\Http\Controllers\CacheController;
use Iperamuna\Hypercacheio\Http\Middleware\HyperCacheioSecurity;

Route::prefix(config('hypercacheio.api_url'))
    ->middleware([HyperCacheioSecurity::class])
    ->group(function () {
        Route::get('/cache/{key}', [CacheController::class, 'get']);
        Route::post('/add/{key}', [CacheController::class, 'add']);
        Route::post('/cache/{key}', [CacheController::class, 'put']);
        Route::delete('/cache/{key}', [CacheController::class, 'forget']);
        Route::post('/lock/{key}', [CacheController::class, 'lock']);
        Route::delete('/lock/{key}', [CacheController::class, 'releaseLock']);
    });
