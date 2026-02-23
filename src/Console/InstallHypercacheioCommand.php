<?php

namespace Iperamuna\Hypercacheio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallHypercacheioCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hypercacheio:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Hyper-Cache-IO and configure the cache store.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Installing Laravel Hyper-Cache-IO...');

        // Publish configuration
        $this->call('vendor:publish', [
            '--tag' => 'hypercacheio-config',
            '--force' => true,
        ]);

        // Configure cache.php
        $this->configureCacheDriver();

        // Update .gitignore
        $this->updateGitignore();

        $this->call('config:clear');

        $this->info('Hyper-Cache-IO installed successfully!');
        $this->warn('Please ensure your .env file has CACHE_DRIVER=hypercacheio set if you want to use it as the default driver.');
        $this->info('Advice: If you change the "sqlite_path" in config/hypercacheio.php, remember to update your .gitignore accordingly.');

        return 0;
    }

    /**
     * Update the project's .gitignore file.
     *
     * @return void
     */
    protected function updateGitignore()
    {
        $gitignorePath = base_path('.gitignore');
        $ignoreEntry = '/storage/hypercacheio/';

        if (! file_exists($gitignorePath)) {
            return;
        }

        $content = file_get_contents($gitignorePath);

        if (! Str::contains($content, $ignoreEntry)) {
            file_put_contents($gitignorePath, "\n".$ignoreEntry."\n", FILE_APPEND);
            $this->info('Added Hypercacheio storage directory to .gitignore.');
        }
    }

    /**
     * Update the cache config file.
     *
     * @return void
     */
    protected function configureCacheDriver()
    {
        $configPath = config_path('cache.php');

        if (! file_exists($configPath)) {
            $this->error('Config file not found: '.$configPath);

            return;
        }

        $configContent = file_get_contents($configPath);

        if (Str::contains($configContent, "'hypercacheio' => [")) {
            $this->info('Hypercacheio store already configured in cache.php.');

            return;
        }

        $storeConfig = "\n        'hypercacheio' => [\n            'driver' => 'hypercacheio',\n        ],";

        // Attempt to insert into the stores array
        // We look for 'stores' => [ and append after it, or ideally before the closing ] of the stores array.
        // Inserting at the beginning of the stores array is unreliable because of formatting.
        // Inserting before default 'array' or 'file' stores might be safer for detection.

        // Let's try to match the start of the stores array
        if (preg_match("/('stores'\s*=>\s*\[)/", $configContent, $matches)) {
            $configContent = str_replace($matches[0], $matches[0].$storeConfig, $configContent);
            file_put_contents($configPath, $configContent);
            $this->info('Added hypercacheio store to cache.php.');
        } else {
            $this->warn('Could not automatically add hypercacheio to cache.php. Please add it manually:');
            $this->line("'hypercacheio' => ['driver' => 'hypercacheio'],");
        }
    }
}
