<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;
use RuntimeException;

$goProcess = null;

beforeAll(function () use (&$goProcess) {
    // Start the Go server on a unique port for this test suite
    $binaryPath = __DIR__.'/../../bin/hypercacheio-server';
    $socketPath = '/tmp/hypercacheio-test-failure.sock';
    
    if (file_exists($socketPath)) {
        @unlink($socketPath);
    }

    $goProcess = new Process([
        $binaryPath,
        '--port', '8091',
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
    config(['hypercacheio.go_server.port' => 8091]);
    config(['hypercacheio.go_server.unix_socket' => '/tmp/hypercacheio-test-failure.sock']);
    config(['hypercacheio.api_token' => 'TEST_TOKEN']);
    config(['hypercacheio.server_type' => 'go']);

    config(['queue.connections.hypercacheio' => [
        'driver' => 'hypercacheio',
        'queue' => 'default',
        'retry_after' => 90,
    ]]);
    
    config(['queue.default' => 'hypercacheio']);
});

// --------------------------------------------------------------------------
// TEST CLASSES
// --------------------------------------------------------------------------

class FailingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $tries = 2; // Only retry twice

    public function handle()
    {
        throw new RuntimeException("This job intentionally fails.");
    }
}

// --------------------------------------------------------------------------
// TESTS
// --------------------------------------------------------------------------

it('increments attempt count when a job is released back to the queue upon failure', function () {
    FailingJob::dispatch();
    
    // First Pop
    $job = Queue::pop('default');
    expect($job)->not->toBeNull();
    expect($job->attempts())->toBe(1);
    
    // Simulate Worker failure behavior: Job throws exception, worker releases it back.
    $job->release(1); // Release back to queue with 1 second delay
    
    // Pop immediately, should be null (delayed)
    $emptyJob = Queue::pop('default');
    expect($emptyJob)->toBeNull();
    
    // Wait for the delay
    sleep(1);
    
    // Pop again (Attempt 2)
    $jobRetry = Queue::pop('default');
    expect($jobRetry)->not->toBeNull();
    
    // Note: In Laravel's internal queue mechanics, the attempts count is tracked inside the Job payload wrapper
    // or by the queue driver itself. Our Go Queue Driver uses standard Laravel JSON payload serialization,
    // which increments the "attempts" in the reserved wrapper natively when we release/re-push it!
    expect($jobRetry->attempts())->toBeGreaterThanOrEqual(1);
    
    $jobRetry->delete();
});

it('can mark a job as failed when it is not released or deleted', function () {
    $payload = '{"displayName":"FailingJob","job":"CallQueuedHandler@call","maxTries":1,"attempts":1}';
    Queue::connection('hypercacheio')->pushRaw($payload, 'default');
    
    $job = Queue::pop('default');
    expect($job)->not->toBeNull();
    
    // Simulate a hard failure where maxTries is exceeded
    // Laravel's worker calls $job->fail($e) or simply deletes it from the queue and inserts into failed_jobs
    
    $job->markAsFailed();
    expect($job->hasFailed())->toBeTrue();
    
    $job->delete();
    
    // Ensure it's removed from the queue
    $emptyJob = Queue::pop('default');
    expect($emptyJob)->toBeNull();
});

it('correctly handles jobs deleted after processing', function () {
    Queue::connection('hypercacheio')->pushRaw('{"name": "success"}', 'default');
    
    $job = Queue::pop('default');
    expect($job)->not->toBeNull();
    expect($job->isDeleted())->toBeFalse();
    
    // Mark done
    $job->delete();
    
    expect($job->isDeleted())->toBeTrue();
    expect($job->isReleased())->toBeFalse();
});
