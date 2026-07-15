<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['queue.default' => 'hypercacheio']);
$id = Illuminate\Support\Facades\Queue::pushRaw('{"job":"test1"}');
$size = Illuminate\Support\Facades\Queue::size('default');
echo "Pushed ID: $id, Size: $size\n";
