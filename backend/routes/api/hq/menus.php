<?php

use App\Http\Controllers\Api\V1\HQ\MenuController;
use App\Http\Controllers\Api\V1\HQ\MenuScheduleController;
use App\Http\Controllers\Api\V1\HQ\MenuSectionController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Menus
// =========================================================================

Route::prefix('menus')->name('api.v1.menus.')->group(function () {
    Route::get('lookup', [MenuController::class, 'lookup'])->name('lookup');
    Route::get('dropdown', [MenuController::class, 'lookup'])->name('dropdown'); // @deprecated
    Route::get('current', [MenuController::class, 'current'])->name('current');
    Route::post('bulk-delete', [MenuController::class, 'bulkDelete'])->name('bulkDelete');
    Route::put('reorder', [MenuController::class, 'reorderMenus'])->name('reorder');

    Route::get('/', [MenuController::class, 'index'])->name('index');
    Route::post('/', [MenuController::class, 'store'])->name('store');
    Route::get('{menu}', [MenuController::class, 'show'])->name('show');
    Route::put('{menu}', [MenuController::class, 'update'])->name('update');
    Route::delete('{menu}', [MenuController::class, 'destroy'])->name('destroy');
    Route::post('{menu}/restore', [MenuController::class, 'restore'])->name('restore');
    Route::post('{menu}/duplicate', [MenuController::class, 'duplicate'])->name('duplicate');

    // Workflow
    Route::post('{menu}/submit', [MenuController::class, 'submit'])->name('submit');
    Route::post('{menu}/approve', [MenuController::class, 'approve'])->name('approve');
    Route::post('{menu}/reject', [MenuController::class, 'reject'])->name('reject');
    Route::post('{menu}/activate', [MenuController::class, 'activate'])->name('activate');
    Route::post('{menu}/deactivate', [MenuController::class, 'deactivate'])->name('deactivate');

    // Master menu
    Route::post('{menu}/clone-to-branch', [MenuController::class, 'cloneToBranch'])->name('cloneToBranch');
    Route::get('{menu}/check-sync', [MenuController::class, 'checkSync'])->name('checkSync');
    Route::post('{menu}/sync-from-master', [MenuController::class, 'syncFromMaster'])->name('syncFromMaster');

    // Products
    Route::post('{menu}/products', [MenuController::class, 'addProducts'])->name('products.store');
    Route::put('{menu}/products/reorder', [MenuController::class, 'reorderProducts'])->name('products.reorder');
    Route::delete('{menu}/products/{menuProduct}', [MenuController::class, 'removeProduct'])->name('products.destroy');
    Route::post('{menu}/products/{menuProduct}/toggle', [MenuController::class, 'toggleProduct'])->name('products.toggle');
    // plan-043 T2.3 — menu-item tax-type override (null = inherit from product).
    Route::patch('{menu}/products/{menuProduct}/tax-type', [MenuController::class, 'updateProductTaxType'])->name('products.taxType');
    // #1218 — the two tiers between the item override and the product. Null on
    // either clears it and the line inherits from the tier below.
    Route::patch('{menu}/tax-type', [MenuController::class, 'updateMenuTaxType'])->name('taxType');
    Route::patch('{menu}/sections/{menuSection}/tax-type', [MenuController::class, 'updateSectionTaxType'])->name('sections.taxType');

    // Bulk layout sync (sections + their products in one call)
    Route::put('{menu}/layout', [MenuController::class, 'syncLayout'])->name('layout.sync');
});

// =========================================================================
//  Menu Sections
// =========================================================================

Route::prefix('menu-sections')->name('api.v1.menuSections.')->group(function () {
    Route::get('/', [MenuSectionController::class, 'index'])->name('index');
    Route::post('/', [MenuSectionController::class, 'store'])->name('store');
    Route::get('{menuSection}', [MenuSectionController::class, 'show'])->name('show');
    Route::put('{menuSection}', [MenuSectionController::class, 'update'])->name('update');
    Route::delete('{menuSection}', [MenuSectionController::class, 'destroy'])->name('destroy');
});

// =========================================================================
//  Menus > Sections (attach/detach sections to a menu)
// =========================================================================

Route::prefix('menus/{menu}/sections')->name('api.v1.menus.sections.')->group(function () {
    Route::put('/', [MenuController::class, 'syncSections'])->name('sync');
});

// =========================================================================
//  Menu Schedules (nested under menus)
// =========================================================================

// Reorder must be registered before apiResource to prevent 'reorder' being
// captured as the {schedule} route parameter.
Route::put('menus/{menu}/schedules/reorder', [MenuScheduleController::class, 'reorder'])
    ->name('api.v1.menus.schedules.reorder');

Route::apiResource('menus.schedules', MenuScheduleController::class)
    ->only(['index', 'store', 'update', 'destroy'])
    ->scoped()
    ->names([
        'index' => 'api.v1.menus.schedules.index',
        'store' => 'api.v1.menus.schedules.store',
        'update' => 'api.v1.menus.schedules.update',
        'destroy' => 'api.v1.menus.schedules.destroy',
    ]);

// =========================================================================
//  Master Menus
// =========================================================================

Route::prefix('master-menus')->name('api.v1.masterMenus.')->group(function () {
    Route::get('/', [MenuController::class, 'masterMenus'])->name('index');
    Route::post('/', [MenuController::class, 'storeMasterMenu'])->name('store');
    Route::get('lookup', [MenuController::class, 'masterMenuLookup'])->name('lookup');
    Route::get('dropdown', [MenuController::class, 'masterMenuLookup'])->name('dropdown'); // @deprecated
    Route::put('reorder', [MenuController::class, 'reorderMasterMenus'])->name('reorder');
});
