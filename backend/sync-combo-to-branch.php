<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Services\Product\MenuService;
use Illuminate\Contracts\Console\Kernel;

echo "Syncing combo to branch menu...\n\n";

// Find ベト屋 brand
$brand = Brand::where('slug', 'betoya')->first();
echo "✓ Brand: {$brand->name}\n";

// Find master menu
$masterMenu = Menu::where('brand_id', $brand->id)
    ->where('is_master', true)
    ->first();
echo "✓ Master menu: {$masterMenu->name} (ID: {$masterMenu->id})\n";

// Find a branch menu cloned from this master
$branchMenu = Menu::where('brand_id', $brand->id)
    ->where('is_master', false)
    ->where('master_menu_id', $masterMenu->id)
    ->whereNotNull('branch_id')
    ->with('branch')
    ->first();

if (! $branchMenu) {
    echo "❌ No branch menu found cloned from master. Creating one...\n";

    // Get first branch for this brand
    $branch = Branch::first();
    if (! $branch) {
        echo "❌ No branch found!\n";
        exit(1);
    }

    echo "Creating branch menu for: {$branch->name}\n";

    // Clone master to branch using MenuService
    $service = app(MenuService::class);
    $branchMenu = $service->cloneToBranch($masterMenu, $branch, [
        'name' => "{$brand->name} Branch Menu ({$branch->name})",
        'description' => 'Cloned from master menu',
    ]);

    echo "✓ Created branch menu: {$branchMenu->name} (ID: {$branchMenu->id})\n";
} else {
    echo "✓ Found branch menu: {$branchMenu->name} (Branch: {$branchMenu->branch->name})\n";
}

// Check what needs to be synced
$service = app(MenuService::class);
$newProducts = $service->checkSyncAvailable($branchMenu);

echo "\nProducts to sync: {$newProducts->count()}\n";
if ($newProducts->count() > 0) {
    foreach ($newProducts as $mp) {
        echo "  - {$mp->product->name}\n";
    }
}

// Sync from master
echo "\nSyncing...\n";
$syncedMenu = $service->syncFromMaster($branchMenu);

echo "\n========================================\n";
echo "✓ Sync complete!\n";
echo "========================================\n";
echo "Branch menu: {$syncedMenu->name}\n";
echo "Branch: {$syncedMenu->branch->name}\n";
echo "Total products: {$syncedMenu->menuProducts()->count()}\n";
echo "Last synced: {$syncedMenu->last_synced_at}\n";
echo "\nYou can now view this menu in the admin or via API:\n";
echo "GET /api/v1/shops/{shop-slug}/menus/{$syncedMenu->id}\n";
echo "========================================\n";
