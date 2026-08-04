<?php

namespace Iperamuna\Hypercacheio\Metrics;

use Vigilance\Metrics\QueueDepth;

/**
 * Extends Vigilance's QueueDepth to support the Hypercacheio queue driver.
 *
 * Vigilance's built-in QueueDepth only covers database, redis, beanstalkd, and sqs.
 * This subclass adds a 'hypercacheio' case that calls the queue's existing size()
 * method, which in turn hits the Go server's /api/hypercacheio/queue/size/{name}
 * endpoint. This makes the Vigilance workload dashboard show live depth instead of n/a.
 */
class HypercacheioQueueDepth extends QueueDepth
{
    /**
     * Number of jobs pending on the given queue, or null when the driver
     * cannot answer.
     */
    public function for(string $connection, string $queue): ?int
    {
        try {
            $driver = config("queue.connections.$connection.driver");

            if ($driver === 'hypercacheio') {
                return $this->hypercacheioDepth($connection, $queue);
            }

            return parent::for($connection, $queue);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Queries the Hypercacheio Go server via the existing HypercacheioQueue::size()
     * method for the pending job count of the given queue.
     */
    protected function hypercacheioDepth(string $connection, string $queue): ?int
    {
        try {
            $q = app('queue')->connection($connection);

            if (method_exists($q, 'size')) {
                return (int) $q->size($queue);
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
