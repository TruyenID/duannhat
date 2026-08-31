<?php

use App\Http\Controllers\Api\V1\HQ\CustomerOrderController;
use Illuminate\Support\Facades\Route;

/*
 * HQ-domain routes — order report + cross-branch void.
 *
 * Mounted from routes/api.php inside the hq/{brandSlug} prefix.
 * Brand admins can view order reports with aggregates across all branches,
 * and void an order from HQ (same teardown as the shop-side void; a reason
 * is mandatory). Every single-order endpoint is brand-scoped in the
 * controller so a slug swap can't reach a sibling brand's order.
 */

Route::prefix('orders')->name('api.v1.hq.orders.')->group(function () {
    Route::get('/', [CustomerOrderController::class, 'index'])->name('index');
    Route::get('{customerOrder}', [CustomerOrderController::class, 'show'])->name('show');
    Route::post('{customerOrder}/void', [CustomerOrderController::class, 'voidOrder'])->name('void');
});
