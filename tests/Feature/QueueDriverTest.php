<?php

use Illuminate\Support\Facades\Queue;
use Iperamuna\Hypercacheio\Queue\HypercacheioQueue;
use Symfony\Component\Process\Process;

$goProcess = null;

beforeAll(function () use (&$goProcess) {
    // Start the Go server on a unique port for this test suite
    $binaryPath = __DIR__.'/../../bin/hypercacheio-server';
    if (! file_exists($binaryPath)) {
        // Compile the binary if it doesn't exist
        $process = new Process(['go', 'build', '-o', '../bin/hypercacheio-server'], __DIR__.'/../../go-server');
        $process->run();
    }

    $socketPath = '/tmp/hypercacheio-test-queue.sock';
    if (file_exists($socketPath)) {
        @unlink($socketPath);
    }

    $goProcess = new Process([
        $binaryPath,
        '--port', '8089',
        '--unix-socket', $socketPath,
        '--token', 'TEST_TOKEN',
        '--ha-mode=false',
    ]);

    $goProcess->start();
    sleep(1); // Wait for the Go server to boot
});

afterAll(function () use (&$goProcess) {
    if ($goProcess) {
        $goProcess->stop();
    }
});

beforeEach(function () {
    config(['hypercacheio.go_server.port' => 8089]);
    config(['hypercacheio.go_server.unix_socket' => '/tmp/hypercacheio-test-queue.sock']);
    config(['hypercacheio.api_token' => 'TEST_TOKEN']);
    config(['hypercacheio.server_type' => 'go']);

    config(['queue.connections.hypercacheio' => [
        'driver' => 'hypercacheio',
        'queue' => 'default',
        'retry_after' => 90,
    ]]);
});

it('can push and pop a job to the go server queue', function () {
    $queue = Queue::connection('hypercacheio');

    expect($queue)->toBeInstanceOf(HypercacheioQueue::class);

    // Push a raw job
    $payload = '{"displayName":"TestJob","job":"CallQueuedHandler@call","maxTries":null}';
    $id = $queue->pushRaw($payload, 'default');

    expect($id)->not->toBeNull()
        ->and(is_string($id))->toBeTrue();

    // Pop the job
    $job = $queue->pop('default');

    expect($job)->not->toBeNull();
    expect($job->getJobId())->toBe($id);
    expect($job->getRawBody())->toBe($payload);

    // Delete the job
    $job->delete();

    // Try to pop again, should be empty
    $emptyJob = $queue->pop('default');
    expect($emptyJob)->toBeNull();
});

it('can release a job back to the queue', function () {
    $queue = Queue::connection('hypercacheio');

    // Push a raw job
    $payload = '{"displayName":"ReleasableJob"}';
    $queue->pushRaw($payload, 'default');

    // Pop the job
    $job = $queue->pop('default');
    expect($job)->not->toBeNull();

    // Release it back with 0 delay
    $job->release();

    // It should be available again immediately
    $poppedAgain = $queue->pop('default');
    expect($poppedAgain)->not->toBeNull();
    expect($poppedAgain->getJobId())->toBe($job->getJobId());

    $poppedAgain->delete();
});

it('can delay jobs correctly', function () {
    $queue = Queue::connection('hypercacheio');

    // Push a job delayed by 2 seconds
    $payload = '{"displayName":"DelayedJob"}';
    $id = $queue->later(2, 'DummyJob', $payload, 'default');

    // Pop immediately, should be null because it is delayed
    $job = $queue->pop('default');
    expect($job)->toBeNull();

    // Wait for 2 seconds
    sleep(2);

    // Now it should be available!
    $delayedJob = $queue->pop('default');
    expect($delayedJob)->not->toBeNull();
    expect($delayedJob->getJobId())->toBe($id);

    $delayedJob->delete();
});
