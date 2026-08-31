<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Branch;
use App\Models\Menu;
use Illuminate\Contracts\Console\Kernel;

$branchSlug = $argv[1] ?? 'sjk';

echo "Finding menus for shop: {$branchSlug}\n\n";

// Find branch
$branch = Branch::where('slug', $branchSlug)->first();

if (! $branch) {
    echo "❌ Shop not found!\n";
    exit(1);
}

echo "✓ Shop: {$branch->name} (slug: {$branch->slug})\n\n";

// Find all menus for this branch
$menus = Menu::where('branch_id', $branch->id)
    ->with(['brand', 'masterMenu'])
    ->get();

echo "Found {$menus->count()} menus:\n\n";

foreach ($menus as $menu) {
    echo "─────────────────────────────────────\n";
    echo "Menu: {$menu->name}\n";
    echo "ID: {$menu->id}\n";
    echo "Brand: {$menu->brand->name} ({$menu->brand->slug})\n";
    echo "Status: {$menu->status}\n";

    if ($menu->master_menu_id) {
        echo "Master: {$menu->masterMenu->name}\n";
    }

    $productCount = $menu->menuProducts()->count();
    $comboCount = $menu->menuProducts()
        ->whereHas('product', fn ($q) => $q->where('is_combo', true))
        ->count();

    echo "Products: {$productCount} (Combos: {$comboCount})\n";
    echo "URL: http://localhost:5430/shop/{$branchSlug}/menus/{$menu->id}\n";
}

echo "─────────────────────────────────────\n";
