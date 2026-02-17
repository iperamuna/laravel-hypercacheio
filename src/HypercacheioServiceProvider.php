<?php

namespace Iperamuna\Hypercacheio;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for loading Hypercacheio components.
 */
class HypercacheioServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/hypercacheio.php' => config_path('hypercacheio.php'),
        ], 'hypercacheio-config');

        $this->loadRoutesFrom(__DIR__.'/../routes/hypercacheio.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallHypercacheioCommand::class,
            ]);
        }

        $this->app->make('cache')->extend('hypercacheio', function ($app, $config) {
            // Merge defaults from config/hypercacheio.php with store-specific config from cache.php
            $mergedConfig = array_merge($app['config']->get('hypercacheio', []), $config);

            return $app['cache']->repository(new HypercacheioStore($mergedConfig));
        });
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hypercacheio.php', 'hypercacheio');
    }
}
