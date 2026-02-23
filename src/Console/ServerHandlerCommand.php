<?php

namespace Iperamuna\Hypercacheio\Console;

use Illuminate\Console\Command;
use Iperamuna\Hypercacheio\HypercacheioService;

class ServerHandlerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hypercacheio:server-handler {action} {--payload= : JSON payload for the action} {--key= : Cache key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Internal handler for Go server to interact with SQLite cache.';

    /**
     * The Hypercacheio service instance.
     */
    protected $service;

    /**
     * Create a new command instance.
     */
    public function __construct(HypercacheioService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $action = $this->argument('action');
        $key = $this->option('key');
        $payload = json_decode($this->option('payload'), true) ?? [];

        switch ($action) {
            case 'get':
                $result = $this->service->get($key);
                $this->output->write(json_encode(['data' => $result]));
                break;
            case 'put':
                $this->service->put($key, $payload['value'] ?? null, $payload['ttl'] ?? null);
                $this->output->write(json_encode(['success' => true]));
                break;
            case 'add':
                $added = $this->service->add($key, $payload['value'] ?? null, $payload['ttl'] ?? null);
                $this->output->write(json_encode(['added' => $added]));
                break;
            case 'forget':
                $this->service->forget($key);
                $this->output->write(json_encode(['success' => true]));
                break;
            case 'flush':
                $this->service->flush();
                $this->output->write(json_encode(['success' => true]));
                break;
            case 'lock':
                $acquired = $this->service->lock($key, $payload['owner'] ?? '', $payload['ttl'] ?? 0);
                $this->output->write(json_encode(['acquired' => $acquired]));
                break;
            case 'releaseLock':
                $released = $this->service->releaseLock($key, $payload['owner'] ?? '');
                $this->output->write(json_encode(['released' => $released]));
                break;
            case 'ping':
                $this->output->write(json_encode($this->service->ping()));
                break;
            default:
                $this->error(json_encode(['error' => 'Unknown action']));

                return 1;
        }

        return 0;
    }
}
