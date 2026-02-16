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

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallHypercachioCommand::class,
            ]);
        }

        $this->app->make('cache')->extend('hypercachio', function ($app, $config) {
            // Merge defaults from config/hypercachio.php with store-specific config from cache.php
            $mergedConfig = array_merge($app['config']->get('hypercachio', []), $config);

            return $app['cache']->repository(new HypercachioStore($mergedConfig));
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
