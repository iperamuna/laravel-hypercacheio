<?php

namespace Iperamuna\Hypercachio;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for loading Hypercachio components.
 */
class HypercachioServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/hypercachio.php' => config_path('hypercachio.php'),
        ], 'hypercachio-config');

        $this->loadRoutesFrom(__DIR__.'/../routes/hypercachio.php');

        Cache::extend('hypercachio', function ($app, $config) {
            // Merge defaults from config/hypercachio.php with store-specific config from cache.php
            $mergedConfig = array_merge(config('hypercachio'), $config);

            return Cache::repository(new HypercachioStore($mergedConfig));
        });
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hypercachio.php', 'hypercachio');
    }
}
