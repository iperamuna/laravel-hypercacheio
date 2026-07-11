<?php

namespace Iperamuna\Hypercacheio\Console;

use Illuminate\Console\Command;

class ServiceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hypercacheio:service-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the Go server binary to the latest version and restart the daemon/service.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        return $this->call('hypercacheio:go-server', [
            'action' => 'service:update',
        ]);
    }
}
