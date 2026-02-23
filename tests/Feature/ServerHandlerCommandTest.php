<?php

use Iperamuna\Hypercacheio\HypercacheioService;

use function Pest\Laravel\artisan;

it('handles get action via server-handler', function () {
    $service = Mockery::mock(HypercacheioService::class);
    $service->shouldReceive('get')->with('test-key')->andReturn('test-value');
    $this->app->instance(HypercacheioService::class, $service);

    artisan('hypercacheio:server-handler get --key=test-key')
        ->expectsOutput('{"data":"test-value"}')
        ->assertExitCode(0);
});

it('handles put action via server-handler', function () {
    $service = Mockery::mock(HypercacheioService::class);
    // Be explicit about the payload we are sending in the test
    $service->shouldReceive('put')->with('test-key', 'test-value', 60)->andReturn(true);
    $this->app->instance(HypercacheioService::class, $service);

    $payload = json_encode(['value' => 'test-value', 'ttl' => 60]);
    artisan('hypercacheio:server-handler', [
        'action' => 'put',
        '--key' => 'test-key',
        '--payload' => $payload,
    ])->assertExitCode(0);
});

it('handles ping action via server-handler', function () {
    $service = Mockery::mock(HypercacheioService::class);
    $service->shouldReceive('ping')->andReturn(['message' => 'pong']);
    $this->app->instance(HypercacheioService::class, $service);

    artisan('hypercacheio:server-handler ping')
        ->expectsOutput('{"message":"pong"}')
        ->assertExitCode(0);
});
