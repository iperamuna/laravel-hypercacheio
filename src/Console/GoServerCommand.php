<?php

namespace Iperamuna\Hypercacheio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GoServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hypercacheio:go-server {action : start|stop|restart|status|compile|make-service}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage the Hypercacheio Go server daemon.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'start':
                $this->start();
                break;
            case 'stop':
                $this->stop();
                break;
            case 'restart':
                $this->stop();
                $this->start();
                break;
            case 'status':
                $this->status();
                break;
            case 'compile':
                $this->compile();
                break;
            case 'make-service':
                $this->makeService();
                break;
            default:
                $this->error('Unknown action: '.$action);

                return 1;
        }

        return 0;
    }

    protected function compile()
    {
        if (! $this->laravel->environment('local')) {
            $this->error('The compile action is only available in local environments.');

            return;
        }

        $this->info('Checking Go installation...');

        if (! $this->isGoInstalled()) {
            $this->warn('Go is not installed.');

            if (! $this->confirm('Do you want to install Go and required libraries?', true)) {
                $this->error('Go is required for compilation. Action aborted.');

                return;
            }

            $this->installGo();
        }

        $this->info('Compiling Go server binaries...');
        $goPath = __DIR__.'/../../go-server';
        $binDir = __DIR__.'/../../build';

        if (! File::exists($binDir)) {
            File::makeDirectory($binDir, 0755, true);
        }

        $result = 1;
        $output = [];

        if (File::exists($goPath.'/Makefile')) {
            $this->info('Found Makefile, building all architectures...');
            $command = "cd $goPath && make all";
            exec($command, $output, $result);
        } else {
            $this->info('Makefile not found, building for current platform only...');
            $binName = $this->getBinaryName();
            $command = "cd $goPath && go build -o ../build/$binName main.go";
            exec($command, $output, $result);
        }

        if ($result === 0) {
            $this->info("Go server compiled successfully to: $binDir");
        } else {
            $this->error('Compilation failed: '.implode("\n", $output));
        }
    }

    protected function isGoInstalled(): bool
    {
        exec('go version 2>&1', $output, $result);

        return $result === 0;
    }

    protected function installGo()
    {
        $this->info('Attempting to install Go...');
        $os = strtolower(PHP_OS_FAMILY);

        if ($os === 'darwin') {
            $this->info('Detected macOS. Checking for Homebrew...');
            exec('brew --version 2>&1', $output, $brewResult);
            if ($brewResult !== 0) {
                $this->error('Homebrew is not installed. Please install it first from https://brew.sh/');

                return;
            }
            $this->info('Installing Go via Homebrew...');
            passthru('brew install go');
        } elseif ($os === 'linux') {
            $this->info('Detected Linux. Attempting to install via apt...');
            passthru('sudo apt-get update && sudo apt-get install -y golang');
        } else {
            $this->error("Automated installation is not supported for OS: $os. Please install Go manually from https://go.dev/doc/install");

            return;
        }

        if ($this->isGoInstalled()) {
            $this->info('Go installed successfully.');
        } else {
            $this->error('Installation failed or Go is still not in your PATH.');
        }
    }

    protected function start()
    {
        $config = config('hypercacheio.go_server');
        $pidPath = $config['pid_path'];

        if (File::exists($pidPath)) {
            $pid = File::get($pidPath);
            if ($this->isProcessRunning($pid)) {
                $this->warn("Go server is already running (PID: $pid)");

                return;
            }
            File::delete($pidPath);
        }

        $binPath = $config['bin_path'] ?? $this->detectBinary();

        if (! $binPath || ! File::exists($binPath)) {
            $this->error('Go server binary not found. Tested path: '.($binPath ?: 'none').". Please run 'php artisan hypercacheio:go-server compile' first.");

            return;
        }

        $this->info('Starting Go server using binary: '.basename($binPath));

        $args = [
            "--port={$config['port']}",
            "--host={$config['host']}",
            '--token='.config('hypercacheio.api_token'),
            '--artisan="'.base_path('artisan').'"',
        ];

        if ($config['ssl']['enabled']) {
            $args[] = '--ssl=true';
            $args[] = "--cert={$config['ssl']['certificate']}";
            $args[] = "--key={$config['ssl']['certificate_key']}";
        }

        $logPath = $config['log_path'];
        $command = "nohup $binPath ".implode(' ', $args)." > $logPath 2>&1 & echo $!";

        $pid = trim(shell_exec($command));

        if ($pid > 0) {
            File::put($pidPath, $pid);
            $this->info("Go server started (PID: $pid)");
        } else {
            $this->error('Failed to start Go server.');
        }
    }

    protected function detectBinary()
    {
        $binDir = __DIR__.'/../../build';
        $binName = $this->getBinaryName();
        $path = $binDir.'/'.$binName;

        if (File::exists($path)) {
            return realpath($path);
        }

        // Fallback to non-platform specific if it exists (for legacy/manual builds)
        $fallback = $binDir.'/hypercacheio-server';
        if (File::exists($fallback)) {
            return realpath($fallback);
        }

        return null;
    }

    protected function getBinaryName()
    {
        $os = strtolower(PHP_OS_FAMILY);
        $arch = strtolower(php_uname('m'));

        if ($arch === 'x86_64') {
            $arch = 'amd64';
        } elseif ($arch === 'aarch64' || $arch === 'arm64') {
            $arch = 'arm64';
        }

        return "hypercacheio-server-$os-$arch";
    }

    protected function stop()
    {
        $pidPath = config('hypercacheio.go_server.pid_path');
        if (! File::exists($pidPath)) {
            $this->warn('Go server is not running.');

            return;
        }

        $pid = File::get($pidPath);
        $this->info("Stopping Go server (PID: $pid)...");

        if ($this->isProcessRunning($pid)) {
            exec("kill $pid");
            sleep(1);
            if ($this->isProcessRunning($pid)) {
                exec("kill -9 $pid");
            }
        }

        File::delete($pidPath);
        $this->info('Go server stopped.');
    }

    protected function status()
    {
        $pidPath = config('hypercacheio.go_server.pid_path');
        if (! File::exists($pidPath)) {
            $this->info('Go server is NOT running.');

            return;
        }

        $pid = File::get($pidPath);
        if ($this->isProcessRunning($pid)) {
            $this->info("Go server is running (PID: $pid)");
            $this->line('Listening on: '.config('hypercacheio.go_server.host').':'.config('hypercacheio.go_server.port'));
        } else {
            $this->warn("Go server PID file exists ($pid), but process is NOT running.");
            File::delete($pidPath);
        }
    }

    protected function makeService()
    {
        $this->info('Generating service configuration files...');

        $config = config('hypercacheio.go_server');
        $binPath = $config['bin_path'] ?? $this->detectBinary();

        if (! $binPath || ! File::exists($binPath)) {
            $this->error('Go server binary not found. Please compile it first.');

            return;
        }

        $argsList = [
            "--port={$config['port']}",
            "--host={$config['host']}",
            '--token='.config('hypercacheio.api_token'),
            '--artisan="'.base_path('artisan').'"',
        ];

        if ($config['ssl']['enabled'] ?? false) {
            $argsList[] = '--ssl=true';
            $argsList[] = "--cert={$config['ssl']['certificate']}";
            $argsList[] = "--key={$config['ssl']['certificate_key']}";
        }

        $fullCommand = "$binPath ".implode(' ', $argsList);
        $user = get_current_user();
        $baseDir = base_path();
        $logPath = $config['log_path'];

        // 1. Systemd (Linux)
        $systemdStubPath = __DIR__.'/../../stubs/systemd.service.stub';
        if (File::exists($systemdStubPath)) {
            $systemd = File::get($systemdStubPath);
            $systemd = str_replace(
                ['{{USER}}', '{{WORKING_DIRECTORY}}', '{{COMMAND}}', '{{LOG_PATH}}'],
                [$user, $baseDir, $fullCommand, $logPath],
                $systemd
            );

            $systemdPath = base_path('hypercacheio-server.service');
            File::put($systemdPath, $systemd);
            $this->info("Systemd service file created: $systemdPath");
        }

        // 2. Launchd (macOS)
        $launchdStubPath = __DIR__.'/../../stubs/launchd.plist.stub';
        if (File::exists($launchdStubPath)) {
            $launchd = File::get($launchdStubPath);

            $plistArgs = "        <string>$binPath</string>";
            foreach ($argsList as $arg) {
                $arg = trim($arg, '"');
                $plistArgs .= "\n        <string>$arg</string>";
            }

            $launchd = str_replace(
                ['{{ARGUMENTS}}', '{{WORKING_DIRECTORY}}', '{{LOG_PATH}}'],
                [$plistArgs, $baseDir, $logPath],
                $launchd
            );

            $plistPath = base_path('iperamuna.hypercacheio.server.plist');
            File::put($plistPath, $launchd);
            $this->info("Launchd plist file created (macOS): $plistPath");
        }

        $this->newLine();
        $this->comment('Installation Instructions:');
        $this->line('- Linux: sudo cp '.base_path('hypercacheio-server.service').' /etc/systemd/system/ && sudo systemctl enable --now hypercacheio-server');
        $this->line('- macOS: cp '.base_path('iperamuna.hypercacheio.server.plist').' ~/Library/LaunchAgents/ && launchctl load ~/Library/LaunchAgents/iperamuna.hypercacheio.server.plist');
    }

    protected function isProcessRunning($pid)
    {
        return (bool) exec("ps -p $pid | grep $pid");
    }
}
