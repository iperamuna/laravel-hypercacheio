<?php

namespace Iperamuna\Hypercacheio\Queue;

use Illuminate\Queue\Connectors\ConnectorInterface;

class HypercacheioConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        // $config contains the configuration from config/queue.php for this connection
        return new HypercacheioQueue($config);
    }
}
