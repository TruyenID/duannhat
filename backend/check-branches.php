<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "=== Branch Check (Both Databases) ===\n\n";

// Check dxs_product
echo "📊 Database: dxs_product\n";
config(['database.default' => 'mysql']);
config(['database.connections.mysql.database' => 'dxs_product']);
DB::purge('mysql');

try {
    $branches = DB::table('branches')->select('id', 'slug', 'name', 'timezone')->get();
    echo "   Branches: {$branches->count()}\n";
    foreach ($branches as $b) {
        echo "   - {$b->slug} | {$b->name} | TZ: ".($b->timezone ?? 'UTC')."\n";
    }
} catch (Exception $e) {
    echo "   ❌ Error: {$e->getMessage()}\n";
}

echo "\n";

// Check tempo
echo "📊 Database: tempo\n";
config(['database.connections.mysql.database' => 'tempo']);
DB::purge('mysql');

try {
    $branches = DB::table('branches')->select('id', 'slug', 'name', 'timezone')->get();
    echo "   Branches: {$branches->count()}\n";
    foreach ($branches as $b) {
        echo "   - {$b->slug} | {$b->name} | TZ: ".($b->timezone ?? 'UTC')."\n";
    }
} catch (Exception $e) {
    echo "   ❌ Error: {$e->getMessage()}\n";
}

echo "\n✅ Done\n";
