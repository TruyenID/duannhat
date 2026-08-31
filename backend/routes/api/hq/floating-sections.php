<?php

use App\Http\Controllers\Api\V1\HQ\FloatingSectionController;
use App\Http\Controllers\Api\V1\HQ\FloatingSectionScheduleController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Floating Sections
// =========================================================================

Route::prefix('floating-sections')->name('api.v1.floatingSections.')->group(function () {
    Route::get('/', [FloatingSectionController::class, 'index'])->name('index');
    Route::post('/', [FloatingSectionController::class, 'store'])->name('store');
    // bulk-delete must be registered before {floatingSection} to avoid being
    // captured as the id param — same pitfall as menus.php's own bulk-delete.
    Route::post('bulk-delete', [FloatingSectionController::class, 'bulkDelete'])->name('bulkDelete');
    Route::get('{floatingSection}', [FloatingSectionController::class, 'show'])->name('show');
    Route::put('{floatingSection}', [FloatingSectionController::class, 'update'])->name('update');
    Route::delete('{floatingSection}', [FloatingSectionController::class, 'destroy'])->name('destroy');
    Route::post('{floatingSection}/clone-to-branch', [FloatingSectionController::class, 'cloneToBranch'])->name('cloneToBranch');
    Route::post('{floatingSection}/duplicate', [FloatingSectionController::class, 'duplicate'])->name('duplicate');

    // Products
    Route::post('{floatingSection}/products', [FloatingSectionController::class, 'addProducts'])->name('products.store');
    Route::put('{floatingSection}/products/reorder', [FloatingSectionController::class, 'reorderProducts'])->name('products.reorder');
    Route::delete('{floatingSection}/products/{floatingSectionProduct}', [FloatingSectionController::class, 'removeProduct'])->name('products.destroy');
    Route::post('{floatingSection}/products/{floatingSectionProduct}/toggle', [FloatingSectionController::class, 'toggleProduct'])->name('products.toggle');
    Route::patch('{floatingSection}/products/{floatingSectionProduct}/tax-type', [FloatingSectionController::class, 'updateProductTaxType'])->name('products.taxType');

    // Per-SKU toggle + promotional price — present on the master too, since
    // an HQ-defined promo price is the whole point of a floating section
    // (unlike Menu, where only the shop ever prices anything).
    Route::post('{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/toggle', [FloatingSectionController::class, 'toggleProductSku'])->name('products.skus.toggle');
    Route::patch('{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/price', [FloatingSectionController::class, 'overrideSkuPrice'])->name('products.skus.price');
    Route::post('{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/price/reset', [FloatingSectionController::class, 'resetSkuPrice'])->name('products.skus.price.reset');
});

// =========================================================================
//  Floating Section Schedules (nested under floating-sections)
// =========================================================================

// Reorder must be registered before apiResource to prevent 'reorder' being
// captured as the {schedule} route parameter — same pitfall as menus.schedules.
Route::put('floating-sections/{floatingSection}/schedules/reorder', [FloatingSectionScheduleController::class, 'reorder'])
    ->name('api.v1.floatingSections.schedules.reorder');

Route::apiResource('floating-sections.schedules', FloatingSectionScheduleController::class)
    ->parameters(['floating-sections' => 'floatingSection', 'schedules' => 'schedule'])
    ->only(['index', 'store', 'update', 'destroy'])
    ->scoped()
    ->names([
        'index' => 'api.v1.floatingSections.schedules.index',
        'store' => 'api.v1.floatingSections.schedules.store',
        'update' => 'api.v1.floatingSections.schedules.update',
        'destroy' => 'api.v1.floatingSections.schedules.destroy',
    ]);
