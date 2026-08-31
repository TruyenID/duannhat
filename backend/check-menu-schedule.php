<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\Menu;
use App\Services\Customer\CustomerMenuService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Check both databases
echo "=== Databases Available ===\n";
echo 'Default DB: '.DB::connection()->getDatabaseName()."\n";
$databases = ['dxs_product', 'tempo'];
foreach ($databases as $db) {
    $count = DB::connection('mysql')->table('information_schema.TABLES')
        ->where('TABLE_SCHEMA', $db)
        ->where('TABLE_NAME', 'branches')
        ->count();
    echo "  - {$db}: branches table ".($count ? 'EXISTS' : 'NOT FOUND')."\n";
}
echo "\n";
use Illuminate\Contracts\Console\Kernel;

$branchSlug = $argv[1] ?? 'ikb';
echo "=== Menu Schedule Check for Branch '{$branchSlug}' ===\n\n";

$branch = Branch::where('slug', $branchSlug)->first();
if (! $branch) {
    echo "❌ Branch '{$branchSlug}' not found!\n";
    echo "Available branches:\n";
    foreach (Branch::all() as $b) {
        echo "  - {$b->slug} ({$b->name})\n";
    }
    exit(1);
}

echo "✅ Branch: {$branch->name}\n";
echo "   ID: {$branch->id}\n";
echo '   Timezone: '.($branch->timezone ?? 'UTC')."\n\n";

$now = Carbon::now($branch->timezone ?? 'UTC');
echo "⏰ Current Time:\n";
echo "   Date/Time: {$now->format('Y-m-d H:i:s (l)')}\n";
echo "   Day of Week: {$now->dayOfWeek} (0=Sun, 1=Mon, ..., 6=Sat)\n";
echo "   Time: {$now->format('H:i:s')}\n\n";

echo "📋 Active Menus for Branch:\n";
$menus = Menu::where('branch_id', $branch->id)
    ->where('status', 'active')
    ->with('schedules')
    ->get();

echo "   Found {$menus->count()} active menu(s)\n\n";

foreach ($menus as $menu) {
    echo "   📌 Menu: {$menu->name}\n";
    echo "      ID: {$menu->id}\n";
    echo "      Priority: {$menu->priority}\n";
    echo "      Schedules: {$menu->schedules->count()}\n";

    if ($menu->schedules->isEmpty()) {
        echo "      ⏰ Always Available (no schedules)\n";
    } else {
        foreach ($menu->schedules as $schedule) {
            $dayBit = 1 << $now->dayOfWeek;
            $matchesDay = ($schedule->days_of_week & $dayBit) > 0;
            $matchesTime = $schedule->start_time <= $now->format('H:i:s')
                        && $schedule->end_time >= $now->format('H:i:s');

            echo "      ⏰ Schedule #{$schedule->id}:\n";
            echo "         Days: {$schedule->days_of_week} (binary: ".decbin($schedule->days_of_week).")\n";
            echo "         Time: {$schedule->start_time} - {$schedule->end_time}\n";
            echo '         Active: '.($schedule->is_active ? 'YES' : 'NO')."\n";
            echo '         Matches Today? '.($matchesDay ? '✅ YES' : '❌ NO')."\n";
            echo '         Matches Time? '.($matchesTime ? '✅ YES' : '❌ NO')."\n";

            if ($schedule->is_active && $matchesDay && $matchesTime) {
                echo "         ✅ THIS SCHEDULE IS ACTIVE NOW\n";
            }
        }
    }
    echo "\n";
}

echo "🔍 Testing CustomerMenuService::getMenuForBranch():\n";
$service = app(CustomerMenuService::class);
$result = $service->getMenuForBranch($branch->id);

if ($result) {
    echo "   ✅ Menu Returned: YES\n";
    echo '   Categories: '.count($result['categories'])."\n";
    if (! empty($result['categories'])) {
        echo "   First Category: {$result['categories'][0]['name']}\n";
        echo '   Items in first category: '.count($result['categories'][0]['items'])."\n";
    }
} else {
    echo "   ❌ Menu Returned: NO (null)\n";
    echo "   → This means NO menu matches current time + day schedule\n";
}

echo "\n✅ Done\n";
