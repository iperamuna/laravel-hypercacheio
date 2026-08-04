<?php

namespace Iperamuna\Hypercacheio;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Iperamuna\Hypercacheio\Queue\HypercacheioConnector;

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

        // Optional Vigilance integration: provide live queue depth for the hypercacheio
        // driver in the Vigilance workload dashboard ("n/a" becomes a real count).
        //
        // We use a plain string class-name check and an anonymous class so that Vigilance
        // is NEVER a hard dependency of this package — it only activates when Vigilance is
        // already present. The anonymous-class `extends` clause is only evaluated at runtime
        // when the `new class` expression is reached, which only happens inside the
        // class_exists branch, so there is no risk of a fatal error when Vigilance is absent.
        if (class_exists('Vigilance\Metrics\QueueDepth')) {
            $this->app->bind('Vigilance\Metrics\QueueDepth', function () {
                return new class extends \Vigilance\Metrics\QueueDepth {
                    public function for(string $connection, string $queue): ?int
                    {
                        if (config("queue.connections.$connection.driver") === 'hypercacheio') {
                            try {
                                $q = app('queue')->connection($connection);

                                return method_exists($q, 'size') ? (int) $q->size($queue) : null;
                            } catch (\Throwable) {
                                return null;
                            }
                        }

                        return parent::for($connection, $queue);
                    }
                };
            });
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
