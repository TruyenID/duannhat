<?php

use App\Http\Controllers\Api\V1\Inventory\DisposalController;
use App\Http\Controllers\Api\V1\Inventory\MaterialBatchController;
use App\Http\Controllers\Api\V1\Inventory\MaterialLotController;
use App\Http\Controllers\Api\V1\Inventory\MaterialLotReservationController;
use App\Http\Controllers\Api\V1\Inventory\ProductionCalculatorController;
use App\Http\Controllers\Api\V1\Inventory\ProductionOrderController;
use App\Http\Controllers\Api\V1\Inventory\StockAlertController;
use App\Http\Controllers\Api\V1\Inventory\StockCountController;
use App\Http\Controllers\Api\V1\Inventory\StockLevelController;
use App\Http\Controllers\Api\V1\Inventory\StockTransactionController;
use App\Http\Controllers\Api\V1\Inventory\StockTransferController;
use App\Http\Controllers\Api\V1\Inventory\WarehouseController;
use App\Http\Controllers\Api\V1\Shop\CatalogLookupController;
use Illuminate\Support\Facades\Route;

/*
 * Inventory routes — shop-scoped warehouses & stock workflows.
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 * All names use the `api.v1.shops.{entity}.{action}` convention.
 */

// =========================================================================
//  Warehouses
// =========================================================================

Route::prefix('warehouses')->name('api.v1.shops.warehouses.')->group(function () {
    Route::get('lookup', [WarehouseController::class, 'lookup'])->name('lookup');
    Route::post('bulk-delete', [WarehouseController::class, 'bulkDelete'])->name('bulkDelete');

    Route::get('/', [WarehouseController::class, 'index'])->name('index');
    Route::post('/', [WarehouseController::class, 'store'])->name('store');
    Route::get('{warehouse}', [WarehouseController::class, 'show'])->name('show');
    Route::put('{warehouse}', [WarehouseController::class, 'update'])->name('update');
    Route::delete('{warehouse}', [WarehouseController::class, 'destroy'])->name('destroy');
    Route::post('{warehouse}/restore', [WarehouseController::class, 'restore'])->name('restore');

    // Workflow
    Route::post('{warehouse}/toggle-active', [WarehouseController::class, 'toggleActive'])->name('toggleActive');
    Route::patch('{warehouse}/settings', [WarehouseController::class, 'updateSettings'])->name('updateSettings');

    // Members
    Route::get('{warehouse}/members', [WarehouseController::class, 'listMembers'])->name('members.index');
    Route::post('{warehouse}/members', [WarehouseController::class, 'addMember'])->name('members.store');
    Route::put('{warehouse}/members/{member}', [WarehouseController::class, 'updateMemberRole'])->name('members.update');
    Route::delete('{warehouse}/members/{member}', [WarehouseController::class, 'removeMember'])->name('members.destroy');
});

// =========================================================================
//  Catalog Lookups (shop-scoped, brand-filtered)
// =========================================================================

Route::get('product-skus/lookup', [CatalogLookupController::class, 'productSkus'])
    ->name('api.v1.shops.product-skus.lookup');
Route::get('materials/lookup', [CatalogLookupController::class, 'materials'])
    ->name('api.v1.shops.materials.lookup');
Route::get('recipes/lookup', [CatalogLookupController::class, 'recipes'])
    ->name('api.v1.shops.recipes.lookup');

// =========================================================================
//  Stock Transactions
// =========================================================================

Route::prefix('stock-transactions')->name('api.v1.shops.stock-transactions.')->group(function () {
    Route::get('/', [StockTransactionController::class, 'index'])->name('index');
    Route::post('/', [StockTransactionController::class, 'store'])->name('store');
    Route::get('{stockTransaction}', [StockTransactionController::class, 'show'])->name('show');
    Route::put('{stockTransaction}', [StockTransactionController::class, 'update'])->name('update');
    Route::delete('{stockTransaction}', [StockTransactionController::class, 'destroy'])->name('destroy');

    // Workflow
    Route::post('{stockTransaction}/submit', [StockTransactionController::class, 'submit'])->name('submit');
    Route::post('{stockTransaction}/approve', [StockTransactionController::class, 'approve'])->name('approve');
    Route::post('{stockTransaction}/cancel', [StockTransactionController::class, 'cancel'])->name('cancel');
});

// =========================================================================
//  Stock Levels
// =========================================================================

Route::prefix('stock-levels')->name('api.v1.shops.stock-levels.')->group(function () {
    Route::get('/', [StockLevelController::class, 'index'])->name('index');
    Route::get('{stockLevel}', [StockLevelController::class, 'show'])->name('show');
    Route::put('{stockLevel}', [StockLevelController::class, 'update'])->name('update');
    Route::get('{stockLevel}/movements', [StockLevelController::class, 'movements'])->name('movements');
});

// =========================================================================
//  Stock Alerts
// =========================================================================

Route::prefix('stock-alerts')->name('api.v1.shops.stock-alerts.')->group(function () {
    Route::get('summary', [StockAlertController::class, 'summary'])->name('summary');
    Route::get('/', [StockAlertController::class, 'index'])->name('index');
});

// =========================================================================
//  Stock Transfers
// =========================================================================

Route::prefix('stock-transfers')->name('api.v1.shops.stock-transfers.')->group(function () {
    Route::get('/', [StockTransferController::class, 'index'])->name('index');
    Route::post('/', [StockTransferController::class, 'store'])->name('store');
    Route::get('{stockTransfer}', [StockTransferController::class, 'show'])->name('show');
    Route::put('{stockTransfer}', [StockTransferController::class, 'update'])->name('update');
    Route::delete('{stockTransfer}', [StockTransferController::class, 'destroy'])->name('destroy');

    // Workflow
    Route::post('{stockTransfer}/submit', [StockTransferController::class, 'submit'])->name('submit');
    Route::post('{stockTransfer}/approve', [StockTransferController::class, 'approve'])->name('approve');
    Route::post('{stockTransfer}/receive', [StockTransferController::class, 'receive'])->name('receive');
    Route::post('{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('cancel');
});

// =========================================================================
//  Stock Counts
// =========================================================================

Route::prefix('stock-counts')->name('api.v1.shops.stock-counts.')->group(function () {
    Route::get('/', [StockCountController::class, 'index'])->name('index');
    Route::post('/', [StockCountController::class, 'store'])->name('store');
    Route::get('{stockCount}', [StockCountController::class, 'show'])->name('show');
    Route::put('{stockCount}', [StockCountController::class, 'update'])->name('update');
    Route::delete('{stockCount}', [StockCountController::class, 'destroy'])->name('destroy');

    // Workflow
    Route::post('{stockCount}/start', [StockCountController::class, 'start'])->name('start');
    Route::post('{stockCount}/add-items', [StockCountController::class, 'addItems'])->name('addItems');
    Route::post('{stockCount}/update-items', [StockCountController::class, 'updateItems'])->name('updateItems');
    Route::post('{stockCount}/submit', [StockCountController::class, 'submit'])->name('submit');
    Route::post('{stockCount}/approve', [StockCountController::class, 'approve'])->name('approve');
    Route::post('{stockCount}/cancel', [StockCountController::class, 'cancel'])->name('cancel');
});

// =========================================================================
//  Material Batches
// =========================================================================

Route::prefix('material-batches')->name('api.v1.shops.material-batches.')->group(function () {
    Route::get('/', [MaterialBatchController::class, 'index'])->name('index');
    Route::post('/', [MaterialBatchController::class, 'store'])->name('store');
    Route::get('{materialBatch}', [MaterialBatchController::class, 'show'])->name('show');
    Route::put('{materialBatch}', [MaterialBatchController::class, 'update'])->name('update');
    Route::delete('{materialBatch}', [MaterialBatchController::class, 'destroy'])->name('destroy');

    // Workflow
    Route::post('{materialBatch}/submit', [MaterialBatchController::class, 'submit'])->name('submit');
    Route::post('{materialBatch}/approve', [MaterialBatchController::class, 'approve'])->name('approve');
    Route::post('{materialBatch}/start', [MaterialBatchController::class, 'start'])->name('start');
    Route::post('{materialBatch}/complete', [MaterialBatchController::class, 'complete'])->name('complete');
    Route::get('{materialBatch}/cost-breakdown', [MaterialBatchController::class, 'costBreakdown'])->name('cost-breakdown');
    Route::post('{materialBatch}/cancel', [MaterialBatchController::class, 'cancel'])->name('cancel');
});

// =========================================================================
//  Material Lot Reservations (#3112) — shop-scoped holds
//
//  The HQ twin lives at /hq/{brandSlug}/material-lot-reservations and stays
//  HQ-role only. This ring is gated by the `*AtShop` abilities, which also
//  demand the lot sit in a warehouse of THIS shop.
// =========================================================================

Route::prefix('material-lot-reservations')->name('api.v1.shops.material-lot-reservations.')->group(function () {
    Route::get('/', [MaterialLotReservationController::class, 'index'])->name('index');
    Route::post('/', [MaterialLotReservationController::class, 'store'])->name('store');

    // Workflow
    Route::post('{reservation}/release', [MaterialLotReservationController::class, 'release'])->name('release');
    Route::post('{reservation}/renew', [MaterialLotReservationController::class, 'renew'])->name('renew');
});

// =========================================================================
//  Production Orders
// =========================================================================

Route::prefix('production-orders')->name('api.v1.shops.production-orders.')->group(function () {
    Route::get('/', [ProductionOrderController::class, 'index'])->name('index');
    Route::post('/', [ProductionOrderController::class, 'store'])->name('store');
    Route::get('{productionOrder}', [ProductionOrderController::class, 'show'])->name('show');
    Route::put('{productionOrder}', [ProductionOrderController::class, 'update'])->name('update');
    Route::delete('{productionOrder}', [ProductionOrderController::class, 'destroy'])->name('destroy');

    // Workflow
    Route::post('{productionOrder}/submit', [ProductionOrderController::class, 'submit'])->name('submit');
    Route::post('{productionOrder}/approve', [ProductionOrderController::class, 'approve'])->name('approve');
    Route::post('{productionOrder}/start', [ProductionOrderController::class, 'start'])->name('start');
    Route::post('{productionOrder}/complete', [ProductionOrderController::class, 'complete'])->name('complete');
    Route::post('{productionOrder}/cancel', [ProductionOrderController::class, 'cancel'])->name('cancel');
});

// =========================================================================
//  Disposals
// =========================================================================

Route::prefix('disposals')->name('api.v1.shops.disposals.')->group(function () {
    // Static paths before wildcard so `/waste-report` isn't matched as an id.
    Route::get('waste-report', [DisposalController::class, 'wasteReport'])->name('wasteReport');
    Route::get('/', [DisposalController::class, 'index'])->name('index');
    Route::get('{disposal}', [DisposalController::class, 'show'])->name('show');
});

// =========================================================================
//  Production Calculator (read-only planner)
// =========================================================================

Route::prefix('production-calculator')->name('api.v1.shops.production-calculator.')->group(function () {
    Route::get('variants', [ProductionCalculatorController::class, 'variants'])->name('variants');
    Route::post('calculate', [ProductionCalculatorController::class, 'calculate'])->name('calculate');
    Route::post('shortage', [ProductionCalculatorController::class, 'shortage'])->name('shortage');
});

// =========================================================================
//  Material Lots (plan-017)
// =========================================================================

Route::prefix('material-lots')->name('api.v1.shops.material-lots.')->group(function () {
    Route::get('expiring', [MaterialLotController::class, 'expiring'])->name('expiring');
    Route::post('receive', [MaterialLotController::class, 'receive'])->name('receive');
    Route::get('/', [MaterialLotController::class, 'index'])->name('index');
    Route::get('{lot}', [MaterialLotController::class, 'show'])->name('show');
    Route::post('{lot}/split', [MaterialLotController::class, 'split'])->name('split');
    Route::post('{lot}/return', [MaterialLotController::class, 'returnQty'])->name('return');
});
