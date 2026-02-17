<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Mock the config_path for testing
    // Testbench doesn't actually have a writeable config/cache.php by default that persists
    // So we need to ensure one exists in our test environment to modify
    if (! file_exists(config_path())) {
        mkdir(config_path(), 0755, true);
    }

    // Create a dummy cache.php for the test
    $content = "<?php\n\nreturn [\n    'stores' => [\n        'array' => [\n            'driver' => 'array',\n        ],\n    ],\n];";
    File::put(config_path('cache.php'), $content);
});

afterEach(function () {
    // Cleanup
    if (File::exists(config_path('cache.php'))) {
        File::delete(config_path('cache.php'));
    }
});

test('it installs hypercacheio config, modifies cache.php and updates gitignore', function () {
    // Ensure .gitignore exists for the test
    $gitignorePath = base_path('.gitignore');
    File::put($gitignorePath, "vendor/\nnode_modules/\n");

    $this->artisan('hypercacheio:install')
        ->expectsOutput('Installing Laravel Hyper-Cache-IO...')
        ->expectsOutput('Added hypercacheio store to cache.php.')
        ->expectsOutput('Added Hypercacheio storage directory to .gitignore.')
        ->expectsOutput('Hyper-Cache-IO installed successfully!')
        ->assertExitCode(0);

    // Verify config/cache.php was modified
    $content = File::get(config_path('cache.php'));
    expect($content)->toContain("'hypercacheio' => [");
    expect($content)->toContain("'driver' => 'hypercacheio'");

    // Verify .gitignore was updated
    $gitignoreContent = File::get($gitignorePath);
    expect($gitignoreContent)->toContain('/storage/cache/hypercacheio/');

    // Cleanup
    File::delete($gitignorePath);
});

test('it does not duplicate config if already installed', function () {
    // Run once
    $this->artisan('hypercacheio:install');

    // Run again
    $this->artisan('hypercacheio:install') // Use correct command name
        ->expectsOutput('Hypercacheio store already configured in cache.php.')
        ->assertExitCode(0);

    // Verify it wasn't duplicated (simple check: count occurrences)
    $content = File::get(config_path('cache.php'));
    $count = substr_count($content, "'hypercacheio' => [");
    expect($count)->toBe(1);
});
