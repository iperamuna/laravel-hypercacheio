<?php

use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->binDir = __DIR__.'/../../build';
    $this->goDir = __DIR__.'/../../go-server';

    if (! File::exists($this->binDir)) {
        File::makeDirectory($this->binDir, 0755, true);
    }
});

it('can generate service files via make-service', function () {
    // Mock the binary existence for detection
    $binName = 'hypercacheio-server-'.strtolower(PHP_OS_FAMILY).'-'.(strtolower(php_uname('m')) === 'x86_64' ? 'amd64' : 'arm64');
    $binPath = $this->binDir.'/'.$binName;
    File::put($binPath, 'dummy-binary');

    artisan('hypercacheio:go-server make-service')
        ->expectsOutputToContain('Generating service configuration files...')
        ->expectsOutputToContain('Systemd service file created')
        ->expectsOutputToContain('Launchd plist file created')
        ->assertExitCode(0);

    expect(File::exists(base_path('hypercacheio-server.service')))->toBeTrue();
    expect(File::exists(base_path('iperamuna.hypercacheio.server.plist')))->toBeTrue();

    // Cleanup
    File::delete(base_path('hypercacheio-server.service'));
    File::delete(base_path('iperamuna.hypercacheio.server.plist'));
    File::delete($binPath);
});

it('fails to start when binary is missing', function () {
    config(['hypercacheio.go_server.bin_path' => '/non/existent/path']);

    artisan('hypercacheio:go-server start')
        ->expectsOutputToContain('Go server binary not found')
        ->assertExitCode(0); // Command returns 0 but shows error message
});

it('can show status when not running', function () {
    $pidPath = storage_path('hypercacheio-server.test.pid');
    config(['hypercacheio.go_server.pid_path' => $pidPath]);

    if (File::exists($pidPath)) {
        File::delete($pidPath);
    }

    artisan('hypercacheio:go-server status')
        ->expectsOutputToContain('Go server is NOT running')
        ->assertExitCode(0);
});
