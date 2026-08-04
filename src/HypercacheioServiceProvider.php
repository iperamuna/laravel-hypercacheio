<?php

namespace Iperamuna\Hypercacheio;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Iperamuna\Hypercacheio\Metrics\HypercacheioQueueDepth;
use Iperamuna\Hypercacheio\Queue\HypercacheioConnector;
use Vigilance\Metrics\QueueDepth;

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

        if (config('hypercacheio.server_type', 'laravel') === 'laravel') {
            $this->loadRoutesFrom(__DIR__.'/../routes/hypercacheio.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallHypercacheioCommand::class,
                Console\ConnectivityCheckCommand::class,
                Console\ServerHandlerCommand::class,
                Console\GoServerCommand::class,
                Console\ServiceUpdateCommand::class,
                Console\HypercacheioTopCommand::class,
            ]);
        }

        $this->app->make('cache')->extend('hypercacheio', function ($app, $config) {
            // Merge defaults from config/hypercacheio.php with store-specific config from cache.php
            $mergedConfig = array_merge($app['config']->get('hypercacheio', []), $config);

            return $app['cache']->repository(new HypercacheioStore($mergedConfig), $config);
        });

        // Register keys macro on Cache Repository
        Cache::macro('keys', function (string $pattern = '*') {
            $store = $this->getStore();

            if (method_exists($store, 'keys')) {
                return $store->keys($pattern);
            }

            return [];
        });

        // Rebind Vigilance's QueueDepth with our extended version so the Vigilance
        // workload dashboard shows live depth for the hypercacheio driver instead of n/a.
        // Done in boot() so all service providers have run and Vigilance is already loaded.
        if (class_exists(QueueDepth::class)) {
            $this->app->bind(QueueDepth::class, HypercacheioQueueDepth::class);
        }
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hypercacheio.php', 'hypercacheio');

        $this->app->singleton(HypercacheioService::class, function ($app) {
            return new HypercacheioService;
        });

        // Register the queue connection early
        $this->app->resolving('queue', function ($queue) {
            $queue->extend('hypercacheio', function () {
                return new HypercacheioConnector;
            });
        });
    }
}
