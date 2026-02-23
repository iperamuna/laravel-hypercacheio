<?php

use Illuminate\Support\Facades\File;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $binDir = __DIR__.'/../../build';

    if (! File::exists($binDir)) {
        File::makeDirectory($binDir, 0755, true);
    }

    config(['hypercacheio.go_server.build_path' => $binDir]);
});

it('can generate service files via make-service', function () {
    $binDir = config('hypercacheio.go_server.build_path');
    $binName = 'hypercacheio-server-'.strtolower(PHP_OS_FAMILY).'-'.(strtolower(php_uname('m')) === 'x86_64' ? 'amd64' : 'arm64');
    $binPath = $binDir.'/'.$binName;
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

it('warns when binary is missing on start', function () {
    config(['hypercacheio.go_server.bin_path' => '/non/existent/path']);

    artisan('hypercacheio:go-server start')
        ->expectsOutputToContain('Go server binary not found')
        ->assertExitCode(0);
});

it('can show daemon status when not running', function () {
    $pidPath = storage_path('hypercacheio-server.test.pid');
    config(['hypercacheio.go_server.pid_path' => $pidPath]);

    if (File::exists($pidPath)) {
        File::delete($pidPath);
    }

    artisan('hypercacheio:go-server status')
        ->expectsOutputToContain('Go server is NOT running')
        ->assertExitCode(0);
});

it('reports unknown action', function () {
    artisan('hypercacheio:go-server unknown-action')
        ->expectsOutputToContain('Unknown action')
        ->assertExitCode(1);
});

it('shows service:status output for current OS', function () {
    // Just verify the command dispatches without crashing (output depends on OS/service state)
    $os = strtolower(PHP_OS_FAMILY);

    if ($os === 'darwin') {
        artisan('hypercacheio:go-server service:status')
            ->expectsOutputToContain('launchd status')
            ->assertExitCode(0);
    } else {
        artisan('hypercacheio:go-server service:status')
            ->expectsOutputToContain('systemd status')
            ->assertExitCode(0);
    }
});

it('aborts service:remove when user declines confirmation', function () {
    artisan('hypercacheio:go-server service:remove')
        ->expectsConfirmation('This will disable and remove the system service. Continue?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);
});

it('shows error when service:start plist missing on macOS', function () {
    if (strtolower(PHP_OS_FAMILY) !== 'darwin') {
        $this->markTestSkipped('macOS only.');
    }

    artisan('hypercacheio:go-server service:start')
        ->expectsOutputToContain('Launchd plist not found')
        ->assertExitCode(0);
});
