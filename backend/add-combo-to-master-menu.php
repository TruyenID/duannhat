<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

echo "Adding combo product to master menu...\n\n";

// Find ベト屋 brand
$brand = Brand::where('slug', 'betoya')->first();
if (! $brand) {
    echo "❌ Brand 'beto-ya' not found\n";
    exit(1);
}
echo "✓ Found brand: {$brand->name}\n";

// Find マスターメニュー
$masterMenu = Menu::where('brand_id', $brand->id)
    ->where('is_master', true)
    ->where('name', 'like', '%マスター%')
    ->first();

if (! $masterMenu) {
    echo "❌ Master menu not found\n";
    exit(1);
}
echo "✓ Found master menu: {$masterMenu->name} (ID: {$masterMenu->id})\n";

// Find 🔥 おすすめ section
$section = $masterMenu->menuSections()
    ->where('name', 'like', '%おすすめ%')
    ->first();

if (! $section) {
    echo "❌ Section '🔥 おすすめ' not found\n";
    exit(1);
}
echo "✓ Found section: {$section->name} (ID: {$section->id})\n";

// Find a combo product for this brand
$combo = Product::where('brand_id', $brand->id)
    ->where('is_combo', true)
    ->first();

if (! $combo) {
    echo "❌ No combo product found for brand {$brand->name}\n";
    exit(1);
}
echo "✓ Found combo: {$combo->name} (ID: {$combo->id})\n";

// Check if combo already in menu
$existing = MenuProduct::where('menu_id', $masterMenu->id)
    ->where('product_id', $combo->id)
    ->where('menu_section_id', $section->id)
    ->first();

if ($existing) {
    echo "✓ Combo already in menu!\n";
} else {
    // Get max display_order in section
    $maxOrder = MenuProduct::where('menu_id', $masterMenu->id)
        ->where('menu_section_id', $section->id)
        ->max('display_order') ?? 0;

    // Add combo to menu
    MenuProduct::create([
        'id' => (string) Str::ulid(),
        'menu_id' => $masterMenu->id,
        'product_id' => $combo->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => $maxOrder + 1,
    ]);

    echo "✓ Added combo to master menu!\n";
}

echo "\n";
echo "========================================\n";
echo "Master Menu Summary\n";
echo "========================================\n";
echo "Menu: {$masterMenu->name}\n";
echo "Section: {$section->name}\n";
echo "Combo: {$combo->name}\n";
echo "\nNow you can sync this to a branch menu using:\n";
echo "POST /api/v1/shops/{{shop-slug}}/menus/{{branch-menu-id}}/sync\n";
echo "\nOr from HQ side:\n";
echo "POST /api/v1/hq/{$brand->slug}/menus/{{branch-menu-id}}/sync-from-master\n";
echo "========================================\n";
