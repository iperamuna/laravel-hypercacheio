<?php

namespace Iperamuna\Hypercacheio\Queue;

use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Iperamuna\Hypercacheio\HypercacheioStore;
use Iperamuna\Hypercacheio\Traits\InteractsWithGoServer;

class HypercacheioQueue extends Queue implements QueueContract, ClearableQueue
{
    use InteractsWithGoServer;

    protected $config;
    
    /**
     * The default queue name.
     *
     * @var string
     */
    protected $default;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->default = $config['queue'] ?? 'default';
        
        // We initialize the HTTP client used to speak to the Go Server
        $this->initializeClient();
    }

    /**
     * Get the size of the queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function size($queue = null)
    {
        $queueName = $this->getQueue($queue);
        
        $response = $this->syncRequest('get', "/api/hypercacheio/queue/size/{$queueName}");
        
        if ($response && isset($response['size'])) {
            return (int) $response['size'];
        }
        
        return 0;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  array  $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $queueName = $this->getQueue($queue);
        
        $response = $this->syncRequest('post', "/api/hypercacheio/queue/push", [
            'queue' => $queueName,
            'payload' => $payload,
        ]);
        
        if ($response && isset($response['id'])) {
            return $response['id'];
        }
        
        return null;
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTimeInterface|\DateInterval|int  $delay
     * @param  string|object  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $queueName = $this->getQueue($queue);
        $payload = $this->createPayload($job, $queue, $data);
        
        $availableAt = $this->availableAt($delay);
        
        $response = $this->syncRequest('post', "/api/hypercacheio/queue/push", [
            'queue' => $queueName,
            'payload' => $payload,
            'delay' => $availableAt,
        ]);
        
        if ($response && isset($response['id'])) {
            return $response['id'];
        }
        
        return null;
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $queueName = $this->getQueue($queue);
        $retryAfter = $this->config['retry_after'] ?? 90;
        
        $response = $this->syncRequest('post', "/api/hypercacheio/queue/pop", [
            'queue' => $queueName,
            'timeout' => $retryAfter,
        ]);
        
        if ($response && isset($response['job'])) {
            return new HypercacheioJob(
                $this->container,
                $this,
                $response['job']['payload'],
                $this->connectionName,
                $queueName,
                $response['job']['id']
            );
        }
        
        return null;
    }

    /**
     * Delete a reserved job from the queue.
     *
     * @param  string  $queue
     * @param  string  $id
     * @return void
     */
    public function deleteReserved($queue, $id)
    {
        $this->syncRequest('post', "/api/hypercacheio/queue/delete", [
            'queue' => $queue,
            'id' => $id,
        ]);
    }
    
    /**
     * Release a reserved job back onto the queue.
     *
     * @param  string  $queue
     * @param  string  $id
     * @param  int  $delay
     * @return void
     */
    public function release($queue, $id, $delay)
    {
        $availableAt = $this->availableAt($delay);
        
        $this->syncRequest('post', "/api/hypercacheio/queue/release", [
            'queue' => $queue,
            'id' => $id,
            'delay' => $availableAt,
        ]);
    }

    /**
     * Get the queue or return the default.
     *
     * @param  string|null  $queue
     * @return string
     */
    public function getQueue($queue)
    {
        return $queue ?: $this->default;
    }

    /**
     * Get the size of the pending jobs queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function pendingSize($queue = null)
    {
        return $this->size($queue); // Mocked for now
    }

    /**
     * Get the size of the delayed jobs queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function delayedSize($queue = null)
    {
        return 0; // Mocked for now
    }

    /**
     * Get the size of the reserved jobs queue.
     *
     * @param  string|null  $queue
     * @return int
     */
    public function reservedSize($queue = null)
    {
        return 0; // Mocked for now
    }

    /**
     * Get the creation time of the oldest pending job.
     *
     * @param  string|null  $queue
     * @return int|null
     */
    public function creationTimeOfOldestPendingJob($queue = null)
    {
        return null;
    }

    /**
     * Delete all of the jobs from the queue.
     *
     * @param  string  $queue
     * @return int
     */
    public function clear($queue)
    {
        $queueName = $this->getQueue($queue);
        
        $response = $this->syncRequest('post', '/api/hypercacheio/queue/clear', [
            'queue' => $queueName
        ]);
        
        if ($response && isset($response['cleared'])) {
            return $response['cleared'];
        }
        
        return 0;
    }
}
