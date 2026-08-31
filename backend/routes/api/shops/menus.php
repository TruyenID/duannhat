<?php

use App\Http\Controllers\Api\V1\Shop\MenuController;
use App\Http\Controllers\Api\V1\Shop\ShopMenuItemSettingsController;
use App\Http\Controllers\Api\V1\Shop\ShopMenuToppingOverrideController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Menus — read + restricted product/SKU operations, scoped to the shop
// =========================================================================

Route::prefix('menus')->name('api.v1.shops.menus.')->group(function () {
    Route::get('/', [MenuController::class, 'index'])->name('index');
    // Day-of-week filter (declared before {menu} so Laravel does not bind "by-day" as a UUID).
    Route::get('by-day/{dayOfWeek}', [MenuController::class, 'indexByDay'])
        ->where('dayOfWeek', '[0-6]')
        ->name('byDay');
    Route::get('{menu}', [MenuController::class, 'show'])->name('show');
    // #3163 — cặp đôi với `products?section_id=`: pill lấy từ đây (rẻ, luôn đủ),
    // món lấy theo từng section. Đăng ký ở CẢ HAI nhóm `shops` và `pos` vì
    // `listProducts` cũng vậy — POS gọi đường nào là tuỳ chế độ, và một nửa
    // thiếu route thì lưới rơi về tải cả thực đơn mà không ai thấy.
    Route::get('{menu}/sections', [MenuController::class, 'listSections'])->name('sections.index');
    Route::get('{menu}/products', [MenuController::class, 'listProducts'])->name('products.index');

    // Per-menu settings (cart timeout override, expandable to other fields later)
    Route::get('{menu}/settings', [ShopMenuItemSettingsController::class, 'show'])->name('settings.show');
    Route::patch('{menu}/settings', [ShopMenuItemSettingsController::class, 'update'])->name('settings.update');

    // Sync
    Route::post('{menu}/sync', [MenuController::class, 'syncFromMaster'])->name('sync');

    // Product toggle
    Route::post('{menu}/products/{menuProduct}/toggle', [MenuController::class, 'toggleProduct'])
        ->name('products.toggle');

    // Bulk toggle every product in one section ("bật tất cả món")
    Route::post('{menu}/sections/{menuSection}/products/bulk-toggle', [MenuController::class, 'bulkToggleSectionProducts'])
        ->name('sections.products.bulkToggle');

    // SKU operations
    Route::post('{menu}/products/{menuProduct}/skus/{menuProductSku}/toggle', [MenuController::class, 'toggleSku'])
        ->name('products.skus.toggle');
    Route::post('{menu}/products/{menuProduct}/skus/{menuProductSku}/price', [MenuController::class, 'overrideSkuPrice'])
        ->name('products.skus.price');
    Route::post('{menu}/products/{menuProduct}/skus/{menuProductSku}/reset-price', [MenuController::class, 'resetSkuPrice'])
        ->name('products.skus.resetPrice');

    // Shop-level topping extra_price / visibility overrides (per menu_product)
    Route::get(
        '{menu}/products/{menuProduct}/topping-groups/{toppingGroup}/overrides',
        [ShopMenuToppingOverrideController::class, 'index']
    )->name('products.toppingGroups.overrides.index');

    Route::put(
        '{menu}/products/{menuProduct}/topping-groups/{toppingGroup}/overrides',
        [ShopMenuToppingOverrideController::class, 'sync']
    )->name('products.toppingGroups.overrides.sync');
});
