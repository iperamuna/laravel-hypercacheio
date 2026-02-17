<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

it('creates the sqlite database inside the specified directory', function () {
    $tempDir = __DIR__.'/temp_storage';
    if (! File::exists($tempDir)) {
        File::makeDirectory($tempDir, 0755, true);
    }

    config(['hypercachio.sqlite_path' => $tempDir]);

    // Trigger store initialization
    Cache::store('hypercachio')->put('test_storage', 'ok', 60);

    expect(File::exists($tempDir.'/hypercachio.sqlite'))->toBeTrue();

    // Cleanup
    File::deleteDirectory($tempDir);
});
