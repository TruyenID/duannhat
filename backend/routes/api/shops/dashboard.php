<?php

use App\Http\Controllers\Api\V1\Shop\DashboardController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — dashboard analytics (read-only).
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 * All endpoints are scoped to the single branch resolved by ResolveShopFromSlug.
 */

Route::prefix('dashboard')->name('api.v1.shops.dashboard.')->group(function () {
    Route::get('kpis', [DashboardController::class, 'kpis'])->name('kpis');
    Route::get('revenue-trend', [DashboardController::class, 'revenueTrend'])->name('revenue-trend');
    Route::get('table-status', [DashboardController::class, 'tableStatus'])->name('table-status');
    Route::get('top-items', [DashboardController::class, 'topItems'])->name('top-items');
    Route::get('production-queue', [DashboardController::class, 'productionQueue'])->name('production-queue');
    Route::get('recent-orders', [DashboardController::class, 'recentOrders'])->name('recent-orders');
    Route::get('branch-reviews', [DashboardController::class, 'branchReviews'])->name('branch-reviews');
});
