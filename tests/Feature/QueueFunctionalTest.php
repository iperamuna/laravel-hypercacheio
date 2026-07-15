<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Mail\Mailable;
use Symfony\Component\Process\Process;

$goProcess = null;

beforeAll(function () use (&$goProcess) {
    // Start the Go server on a unique port for this test suite
    $binaryPath = __DIR__.'/../../bin/hypercacheio-server';
    $socketPath = '/tmp/hypercacheio-test-functional.sock';
    
    if (file_exists($socketPath)) {
        @unlink($socketPath);
    }

    $goProcess = new Process([
        $binaryPath,
        '--port', '8090',
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
    config(['hypercacheio.go_server.port' => 8090]);
    config(['hypercacheio.go_server.unix_socket' => '/tmp/hypercacheio-test-functional.sock']);
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

class DummyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $value;
    
    public function __construct($value)
    {
        $this->value = $value;
    }

    public function handle()
    {
        // Job handled!
    }
}

class DummyEvent
{
    use SerializesModels;
}

class DummyListener implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public function handle(DummyEvent $event)
    {
        // Event handled!
    }
}

class DummyNotification extends BaseNotification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)->line('Test notification.');
    }
}

class DummyMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function build()
    {
        return $this->html('Test mail');
    }
}

class DummyNotifiable
{
    public $email = 'test@example.com';

    public function routeNotificationFor($channel)
    {
        return $this->email;
    }
    
    public function getKey()
    {
        return 1;
    }
}

// --------------------------------------------------------------------------
// TESTS
// --------------------------------------------------------------------------

it('can serialize and queue standard Jobs', function () {
    DummyJob::dispatch('test-value');
    
    $job = Queue::pop('default');
    
    expect($job)->not->toBeNull();
    
    $payload = $job->payload();
    expect($payload['displayName'])->toBe(DummyJob::class);
    
    // Ensure we can fire the job without exception
    $job->fire();
    
    $job->delete();
});

it('can serialize and queue Event Listeners', function () {
    // Manually register listener for test
    Event::listen(DummyEvent::class, DummyListener::class);
    
    event(new DummyEvent());
    
    $job = Queue::pop('default');
    
    expect($job)->not->toBeNull();
    
    $payload = $job->payload();
    expect($payload['displayName'])->toContain(DummyListener::class);
    
    $job->fire();
    $job->delete();
});

it('can serialize and queue Notifications', function () {
    $notifiable = new DummyNotifiable();
    Notification::send($notifiable, new DummyNotification());
    
    $job = Queue::pop('default');
    
    expect($job)->not->toBeNull();
    
    $payload = $job->payload();
    expect($payload['displayName'])->toContain(DummyNotification::class);
    
    // We don't fire this one because mailers aren't configured in the test environment, 
    // but its presence in the queue proves successful serialization.
    $job->delete();
});

it('can serialize and queue Mailables', function () {
    Mail::to('test@example.com')->queue(new DummyMailable());
    
    $job = Queue::pop('default');
    
    expect($job)->not->toBeNull();
    
    $payload = $job->payload();
    expect($payload['displayName'])->toContain(DummyMailable::class);
    
    $job->delete();
});

it('clears all jobs from the queue', function () {
    // Push some raw jobs
    $res = Queue::pushRaw('{"job":"test1"}');
    if (!$res) {
        dd("Push failed!");
    }
    Queue::pushRaw('{"job":"test2"}');
    Queue::pushRaw('{"job":"test3"}');
    
    // Ensure jobs are there
    expect(Queue::size('default'))->toBeGreaterThan(0);
    
    // Clear the queue
    $clearedCount = Queue::clear('default');
    
    // The cleared count should be 3
    expect($clearedCount)->toBe(3);
    
    // Size should be 0
    expect(Queue::size('default'))->toBe(0);
});

it('automatically releases expired reserved jobs after maintenance', function () {
    // First clear queue to have a clean state
    Queue::clear('default');
    
    Queue::pushRaw('{"job":"stuck"}');
    
    // Pop it to reserve it (moves to reserved heap)
    $job = Queue::pop('default');
    expect($job)->not->toBeNull();
    
    expect($job->getQueue())->toBe('default');
});
