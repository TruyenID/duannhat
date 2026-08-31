<?php

use App\Http\Controllers\Api\V1\Shop\ShopBranchSettingsController;
use App\Http\Controllers\Api\V1\Shop\ShopInfoController;
use App\Http\Controllers\Api\V1\Shop\ShopOrderSettingsController;
use App\Http\Controllers\Api\V1\Shop\ShopTakeawayPaymentSettingsController;
use App\Http\Controllers\Api\V1\Shop\TableController;
use App\Http\Controllers\Api\V1\Shop\TableDefaultsController;
use App\Http\Controllers\Api\V1\Shop\ZoneController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — physical layout (info, zones, tables).
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix:
 *
 *   Route::prefix('shops/{shopSlug}')
 *     ->middleware([ResolveShopFromSlug::class])
 *     ->group(function () {
 *         foreach (glob(__DIR__.'/api/shops/*.php') as $file) {
 *             require $file;
 *         }
 *     });
 *
 * Workflow / lookup actions use POST per docs/contributing/route.md rule 4.
 * Lookup routes are declared BEFORE apiResource so the {model} segment
 * does not capture them.
 */

// =========================================================================
//  Shop info (single-row endpoint used by shop layout for slug validation)
// =========================================================================

Route::get('/', [ShopInfoController::class, 'show'])->name('api.v1.shops.show');

// =========================================================================
//  Order Settings
// =========================================================================

Route::prefix('settings/order')->name('api.v1.shops.settings.order.')->group(function () {
    Route::get('/', [ShopOrderSettingsController::class, 'show'])->name('show');
    Route::patch('/', [ShopOrderSettingsController::class, 'update'])->name('update');
});

Route::prefix('settings/branch')->name('api.v1.shops.settings.branch.')->group(function () {
    Route::get('/', [ShopBranchSettingsController::class, 'show'])->name('show');
    Route::patch('/', [ShopBranchSettingsController::class, 'update'])->name('update');
});

Route::prefix('settings/takeaway-payment')->name('api.v1.shops.settings.takeaway-payment.')->group(function () {
    Route::get('/', [ShopTakeawayPaymentSettingsController::class, 'show'])->name('show');
    Route::patch('/', [ShopTakeawayPaymentSettingsController::class, 'update'])->name('update');
});

// =========================================================================
//  Zones
// =========================================================================

Route::prefix('zones')->name('api.v1.shops.zones.')->group(function () {
    Route::get('lookup', [ZoneController::class, 'lookup'])->name('lookup');

    Route::get('/', [ZoneController::class, 'index'])->name('index');
    Route::post('/', [ZoneController::class, 'store'])->name('store');
    Route::get('{zone}', [ZoneController::class, 'show'])->name('show');
    Route::put('{zone}', [ZoneController::class, 'update'])->name('update');
    Route::delete('{zone}', [ZoneController::class, 'destroy'])->name('destroy');
    Route::post('{zone}/restore', [ZoneController::class, 'restore'])->name('restore')->withTrashed();
    Route::post('{zone}/toggle-active', [ZoneController::class, 'toggleActive'])->name('toggleActive');
});

// =========================================================================
//  Tables
// =========================================================================

Route::prefix('tables')->name('api.v1.shops.tables.')->group(function () {
    // HQ default tables (issue #890) — declared BEFORE {table} so the
    // "defaults" segment is never captured as a table id.
    Route::get('defaults/preview', [TableDefaultsController::class, 'preview'])->name('defaults.preview');
    Route::post('defaults/apply', [TableDefaultsController::class, 'apply'])->name('defaults.apply');

    Route::get('/', [TableController::class, 'index'])->name('index');
    Route::post('/', [TableController::class, 'store'])->name('store');
    Route::get('{table}', [TableController::class, 'show'])->name('show');
    Route::put('{table}', [TableController::class, 'update'])->name('update');
    Route::delete('{table}', [TableController::class, 'destroy'])->name('destroy');
    Route::post('{table}/restore', [TableController::class, 'restore'])->name('restore')->withTrashed();
    Route::post('{table}/toggle-active', [TableController::class, 'toggleActive'])->name('toggleActive');

    // Runtime status (Shop Staff allowed)
    Route::post('{table}/status', [TableController::class, 'changeStatus'])->name('changeStatus');
    Route::get('{table}/status-history', [TableController::class, 'statusHistory'])->name('statusHistory');

    // QR token rotation (Manager+Admin only — no /qr image endpoint, frontend renders client-side)
    Route::post('{table}/regenerate-qr', [TableController::class, 'regenerateQr'])->name('regenerateQr');
});
