<?php

use App\Http\Controllers\Api\V1\Shop\CustomerOrderController;
use App\Http\Controllers\Api\V1\Shop\OrderPaymentController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — customer order lifecycle.
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 * Branch staff can create, checkout, void, and manage order items + payments.
 */

Route::prefix('orders')->name('api.v1.shops.orders.')->group(function () {
    // Init + Update
    Route::put('{customerOrder}/init', [CustomerOrderController::class, 'init'])->name('init');
    Route::put('{customerOrder}', [CustomerOrderController::class, 'update'])->name('update');

    // Workflow actions
    Route::post('{customerOrder}/confirm', [CustomerOrderController::class, 'confirm'])->name('confirm');
    Route::post('{customerOrder}/checkout', [CustomerOrderController::class, 'checkout'])->name('checkout');
    // #2479 — đường ngược của checkout; xem chú thích ở routes/api/pos.php.
    Route::post('{customerOrder}/reopen', [CustomerOrderController::class, 'reopen'])->name('reopen');
    // Cancelling a shop order IS a void — same end state, same teardown. The
    // separate `/cancel` alias was removed so staff always record a reason;
    // POS and workstation already only ever spoke `/void`.
    Route::post('{customerOrder}/void', [CustomerOrderController::class, 'voidOrder'])->name('void');

    // Items
    Route::post('{customerOrder}/items', [CustomerOrderController::class, 'addItem'])->name('items.store');
    Route::patch('{customerOrder}/items/{item}', [CustomerOrderController::class, 'updateItem'])->name('items.update');
    Route::post('{customerOrder}/items/{item}/void', [CustomerOrderController::class, 'voidItem'])->name('items.void');
    Route::delete('{customerOrder}/items/{item}', [CustomerOrderController::class, 'removeItem'])->name('items.destroy');

    // Split bill
    Route::get('{customerOrder}/split-bill', [CustomerOrderController::class, 'splitBill'])->name('split-bill');

    // Table management
    Route::post('{customerOrder}/merge-table', [CustomerOrderController::class, 'mergeTable'])->name('merge-table');
    Route::post('{customerOrder}/unmerge-table', [CustomerOrderController::class, 'unmergeTable'])->name('unmerge-table');

    // Coupon (plan-019)
    Route::post('{customerOrder}/apply-coupon', [CustomerOrderController::class, 'applyCoupon'])->name('apply-coupon');
    Route::delete('{customerOrder}/coupon', [CustomerOrderController::class, 'releaseCoupon'])->name('release-coupon');

    // Payments
    Route::get('{customerOrder}/payments', [OrderPaymentController::class, 'index'])->name('payments.index');
    Route::post('{customerOrder}/payments', [OrderPaymentController::class, 'store'])->name('payments.store');
    Route::post('{customerOrder}/payments/{payment}/confirm', [OrderPaymentController::class, 'confirm'])->name('payments.confirm');
    Route::post('{customerOrder}/payments/{payment}/refund', [OrderPaymentController::class, 'refund'])->name('payments.refund');

    // CRUD
    Route::get('/', [CustomerOrderController::class, 'index'])->name('index');
    Route::post('/', [CustomerOrderController::class, 'store'])->name('store');
    Route::get('{customerOrder}', [CustomerOrderController::class, 'show'])->name('show');
    Route::delete('{customerOrder}', [CustomerOrderController::class, 'destroy'])->name('destroy');

    // Plan-021 — POS: auto-close old order + create new order for table
    Route::post('continue-table', [CustomerOrderController::class, 'continueTable'])->name('continue-table');
});
