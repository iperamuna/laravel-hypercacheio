<?php

namespace Iperamuna\Hypercacheio\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

class HypercacheioJob extends Job implements JobContract
{
    /**
     * The Hypercacheio queue instance.
     *
     * @var \Iperamuna\Hypercacheio\Queue\HypercacheioQueue
     */
    protected $hypercacheio;

    /**
     * The raw job payload.
     *
     * @var string
     */
    protected $jobPayload;

    /**
     * The Hypercacheio job ID.
     *
     * @var string
     */
    protected $jobId;

    /**
     * Create a new job instance.
     *
     * @param  \Illuminate\Container\Container  $container
     * @param  \Iperamuna\Hypercacheio\Queue\HypercacheioQueue  $hypercacheio
     * @param  string  $payload
     * @param  string  $connectionName
     * @param  string  $queue
     * @param  string  $jobId
     * @return void
     */
    public function __construct(Container $container, HypercacheioQueue $hypercacheio, $payload, $connectionName, $queue, $jobId)
    {
        $this->container = $container;
        $this->hypercacheio = $hypercacheio;
        $this->jobPayload = $payload;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
        $this->jobId = $jobId;
    }

    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        return $this->jobId;
    }

    /**
     * Get the raw body string for the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        return $this->jobPayload;
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        return ($this->payload()['attempts'] ?? 0) + 1;
    }

    /**
     * Delete the job from the queue.
     *
     * @return void
     */
    public function delete()
    {
        parent::delete();

        $this->hypercacheio->deleteReserved($this->queue, $this->jobId);
    }

    /**
     * Release the job back into the queue.
     *
     * @param  int  $delay
     * @return void
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        $this->hypercacheio->release($this->queue, $this->jobId, $delay);
    }
}
