<?php

namespace Iperamuna\Hypercacheio\Queue;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

class HypercacheioConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     *
     * @return Queue
     */
    public function connect(array $config)
    {
        // $config contains the configuration from config/queue.php for this connection
        return new HypercacheioQueue($config);
    }
}
