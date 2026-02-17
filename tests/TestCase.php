<?php

namespace Iperamuna\Hypercacheio\Tests;

use Iperamuna\Hypercacheio\HypercacheioServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            HypercacheioServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('hypercacheio.role', 'primary');
        $app['config']->set('hypercacheio.primary_url', 'http://test-server.test/api/hypercacheio');
        $app['config']->set('hypercacheio.async_requests', false);
        $app['config']->set('hypercacheio.sqlite_path', __DIR__.'/temp');
        $app['config']->set('cache.stores.hypercacheio', [
            'driver' => 'hypercacheio',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
    }
}
