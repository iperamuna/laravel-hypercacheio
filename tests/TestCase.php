<?php

namespace Iperamuna\Hypercachio\Tests;

use Iperamuna\Hypercachio\HypercachioServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            HypercachioServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('hypercachio.role', 'primary');
        $app['config']->set('hypercachio.primary_url', 'http://test-server.test/api/hypercachio');
        $app['config']->set('hypercachio.async_requests', false);
        $app['config']->set('hypercachio.sqlite_path', __DIR__.'/temp/cache.sqlite');
        $app['config']->set('cache.stores.hypercachio', [
            'driver' => 'hypercachio',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
    }
}
