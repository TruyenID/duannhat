<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Menu;
use App\Services\Product\MenuService;
use Illuminate\Contracts\Console\Kernel;

$menuId = $argv[1] ?? '019ddcac-4742-73c0-84ee-4b8ab8735666';

echo "Syncing menu: {$menuId}\n\n";

// Find the menu
$branchMenu = Menu::with(['branch', 'masterMenu'])->find($menuId);

if (! $branchMenu) {
    echo "❌ Menu not found!\n";
    exit(1);
}

echo "✓ Found menu: {$branchMenu->name}\n";

if ($branchMenu->branch) {
    echo "  Branch: {$branchMenu->branch->name}\n";
}

if (! $branchMenu->master_menu_id) {
    echo "❌ This menu is not cloned from a master menu.\n";
    echo '  is_master: '.($branchMenu->is_master ? 'true' : 'false')."\n";
    exit(1);
}

echo "  Master menu ID: {$branchMenu->master_menu_id}\n";
echo "  Master menu: {$branchMenu->masterMenu->name}\n";

// Check what needs to be synced
$service = app(MenuService::class);
$newProducts = $service->checkSyncAvailable($branchMenu);

echo "\nProducts available to sync: {$newProducts->count()}\n";
if ($newProducts->count() > 0) {
    foreach ($newProducts as $mp) {
        echo "  - {$mp->product->name}";
        if ($mp->menuSection) {
            echo " (Section: {$mp->menuSection->name})";
        }
        if ($mp->product->is_combo) {
            echo ' [COMBO]';
        }
        echo "\n";
    }
} else {
    echo "  No new products to sync.\n";
    exit(0);
}

// Sync from master
echo "\nSyncing now...\n";
$syncedMenu = $service->syncFromMaster($branchMenu);

echo "\n========================================\n";
echo "✓ Sync complete!\n";
echo "========================================\n";
echo "Menu: {$syncedMenu->name}\n";
if ($syncedMenu->branch) {
    echo "Branch: {$syncedMenu->branch->name}\n";
}
echo "Total products: {$syncedMenu->menuProducts()->count()}\n";
echo "Last synced: {$syncedMenu->last_synced_at}\n";
echo "\nRefresh the page to see the new products:\n";
echo "http://localhost:5430/shop/sjk/menus/{$menuId}\n";
echo "========================================\n";
