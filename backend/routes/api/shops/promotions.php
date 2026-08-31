<?php

use App\Http\Controllers\Api\V1\Shop\MenuPromotionController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain — MenuPromotion CRUD + toggle (plan-019).
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 * shop-manager creates / updates / deletes / toggles promotions for
 * their branch.
 */

Route::prefix('promotions')->name('api.v1.shops.promotions.')->group(function () {
    Route::get('/', [MenuPromotionController::class, 'index'])->name('index');
    Route::post('/', [MenuPromotionController::class, 'store'])->name('store');
    Route::get('{promotion}', [MenuPromotionController::class, 'show'])->name('show');
    Route::get('{promotion}/recent-items', [MenuPromotionController::class, 'recentItems'])->name('recentItems');
    Route::put('{promotion}', [MenuPromotionController::class, 'update'])->name('update');
    Route::delete('{promotion}', [MenuPromotionController::class, 'destroy'])->name('destroy');
    Route::post('{promotion}/toggle', [MenuPromotionController::class, 'toggle'])->name('toggle');
    Route::post('{promotion}/restore', [MenuPromotionController::class, 'restore'])->name('restore');
    Route::post('bulk-delete', [MenuPromotionController::class, 'bulkDelete'])->name('bulkDelete');
});
