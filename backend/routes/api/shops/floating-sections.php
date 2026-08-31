<?php

use App\Http\Controllers\Api\V1\Shop\FloatingSectionController;
use App\Http\Controllers\Api\V1\Shop\FloatingSectionScheduleController;
use App\Http\Controllers\Api\V1\Shop\ShopFloatingSectionToppingOverrideController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Floating Sections — read + restricted product/schedule operations,
//  scoped to the shop's own branch clone (see FloatingSectionService::
//  cloneToBranch). No create/delete — those stay HQ-only.
// =========================================================================

Route::prefix('floating-sections')->name('api.v1.shops.floatingSections.')->group(function () {
    Route::get('/', [FloatingSectionController::class, 'index'])->name('index');
    Route::get('{floatingSection}', [FloatingSectionController::class, 'show'])->name('show');
    Route::post('{floatingSection}/sync', [FloatingSectionController::class, 'syncFromMaster'])->name('sync');

    Route::post('{floatingSection}/products/{floatingSectionProduct}/toggle', [FloatingSectionController::class, 'toggleProduct'])
        ->name('products.toggle');
    Route::post('{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/toggle', [FloatingSectionController::class, 'toggleSku'])
        ->name('products.skus.toggle');
    Route::patch('{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/price', [FloatingSectionController::class, 'overrideSkuPrice'])
        ->name('products.skus.price');
    Route::post('{floatingSection}/products/{floatingSectionProduct}/skus/{sku}/price/reset', [FloatingSectionController::class, 'resetSkuPrice'])
        ->name('products.skus.price.reset');

    // Shop-level topping extra_price / visibility overrides (per floating section
    // product) — mirrors the menu topping-override routes (tier-1 of 3-tier).
    Route::get(
        '{floatingSection}/products/{floatingSectionProduct}/topping-groups/{toppingGroup}/overrides',
        [ShopFloatingSectionToppingOverrideController::class, 'index']
    )->name('products.toppingGroups.overrides.index');
    Route::put(
        '{floatingSection}/products/{floatingSectionProduct}/topping-groups/{toppingGroup}/overrides',
        [ShopFloatingSectionToppingOverrideController::class, 'sync']
    )->name('products.toppingGroups.overrides.sync');

    // Shop schedule access is read + toggle + time-override — no create/delete.
    Route::get('{floatingSection}/schedules', [FloatingSectionScheduleController::class, 'index'])->name('schedules.index');
    Route::post('{floatingSection}/schedules/{schedule}/toggle', [FloatingSectionScheduleController::class, 'toggle'])
        ->name('schedules.toggle');
    Route::put('{floatingSection}/schedules/{schedule}/override', [FloatingSectionScheduleController::class, 'override'])
        ->name('schedules.override');
    Route::delete('{floatingSection}/schedules/{schedule}/override', [FloatingSectionScheduleController::class, 'resetOverride'])
        ->name('schedules.override.reset');
});
