<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['queue.default' => 'hypercacheio']);
$id = Queue::pushRaw('{"job":"test1"}');
$size = Queue::size('default');
echo "Pushed ID: $id, Size: $size\n";
