<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = app('db');
$batch = $db->table('migrations')->max('batch') + 1;

$files = glob(__DIR__.'/database/migrations/omnify/*.php');
$marked = 0;
foreach ($files as $file) {
    $name = basename($file, '.php');
    $exists = $db->table('migrations')->where('migration', $name)->exists();
    if (! $exists) {
        $db->table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
        $marked++;
        echo "Marked: $name\n";
    }
}
echo "Total marked: $marked\n";
