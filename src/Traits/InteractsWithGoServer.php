<?php

namespace Iperamuna\Hypercacheio\Traits;

use Illuminate\Support\Facades\Http;

trait InteractsWithGoServer
{
    /**
     * The primary server URL.
     */
    protected string $primaryUrl = '';

    /**
     * Unix domain socket path for local Go server communication.
     */
    protected string $unixSocketPath = '';

    /**
     * The API token for requests.
     */
    protected string $apiToken = '';

    /**
     * The HTTP timeout for requests.
     */
    protected int $timeout = 1;

    protected function initializeClient()
    {
        $config = config('hypercacheio', []);

        $goConfig = $config['go_server'] ?? [];
        $port = $goConfig['port'] ?? 8080;
        $this->primaryUrl = "http://127.0.0.1:{$port}";
        $this->unixSocketPath = $goConfig['unix_socket'] ?? '';
        $this->apiToken = $config['api_token'] ?? '';
        $this->timeout = $config['timeout'] ?? 1;
    }

    protected function syncRequest(string $method, string $endpoint, array $payload = [])
    {
        try {
            $options = [];
            if (! empty($this->unixSocketPath)) {
                $options['curl'] = [CURLOPT_UNIX_SOCKET_PATH => $this->unixSocketPath];
            }

            $response = Http::timeout($this->timeout)
                ->withOptions($options)
                ->withHeaders([
                    'X-Hypercacheio-Token' => $this->apiToken,
                    'X-Hypercacheio-Server-ID' => gethostname(),
                ])
                ->$method("{$this->primaryUrl}{$endpoint}", $payload);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            throw $e;
        }

        return null;
    }
}
