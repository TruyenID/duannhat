<?php

use App\Http\Controllers\Api\V1\HQ\CouponController;
use Illuminate\Support\Facades\Route;

Route::prefix('coupons')->name('api.v1.hq.coupons.')->group(function () {
    Route::get('/', [CouponController::class, 'index'])->name('index');
    Route::post('/', [CouponController::class, 'store'])->name('store');
    Route::post('bulk-delete', [CouponController::class, 'bulkDelete'])->name('bulkDelete');
    Route::get('{coupon}', [CouponController::class, 'show'])->name('show');
    Route::put('{coupon}', [CouponController::class, 'update'])->name('update');
    Route::delete('{coupon}', [CouponController::class, 'destroy'])->name('destroy');
    Route::post('{coupon}/restore', [CouponController::class, 'restore'])->name('restore');
    Route::post('{coupon}/pause', [CouponController::class, 'pause'])->name('pause');
    Route::post('{coupon}/resume', [CouponController::class, 'resume'])->name('resume');
    Route::get('{coupon}/redemptions', [CouponController::class, 'redemptions'])->name('redemptions');
});
