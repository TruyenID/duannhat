<?php

use App\Http\Controllers\Api\V1\HQ\DashboardController;
use Illuminate\Support\Facades\Route;

/*
 * HQ-domain routes — dashboard analytics (read-only).
 *
 * Mounted from routes/api.php inside the hq/{brandSlug} prefix.
 * All endpoints aggregate data across every branch of the brand.
 */

Route::prefix('dashboard')->name('api.v1.hq.dashboard.')->group(function () {
    Route::get('kpis', [DashboardController::class, 'kpis'])->name('kpis');
    Route::get('revenue-chart', [DashboardController::class, 'revenueChart'])->name('revenue-chart');
    Route::get('category-sales', [DashboardController::class, 'categorySales'])->name('category-sales');
    Route::get('shop-performance', [DashboardController::class, 'shopPerformance'])->name('shop-performance');
    Route::get('top-products', [DashboardController::class, 'topProducts'])->name('top-products');
    Route::get('recent-orders', [DashboardController::class, 'recentOrders'])->name('recent-orders');
});
