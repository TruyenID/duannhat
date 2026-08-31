<?php

use App\Http\Controllers\Api\V1\Shop\CustomerController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — customer management.
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 * Branch staff can manage customers registered to their branch.
 */

Route::prefix('customers')->name('api.v1.shops.customers.')->group(function () {
    // POS convenience: exact-phone upsert for create-order flow
    Route::post('find-or-create', [CustomerController::class, 'findOrCreate'])->name('findOrCreate');

    Route::post('{customer}/restore', [CustomerController::class, 'restore'])->name('restore')->withTrashed();

    // POS payment reminder: list a customer's unpaid orders (paying status,
    // paid < total) so PaymentDialog can surface old debt when they return.
    Route::get('{customer}/outstanding', [CustomerController::class, 'outstanding'])->name('outstanding');

    // #1718 — sổ điểm của khách, nhìn từ cửa hàng. Ví điểm là TOÀN CỤC theo
    // khách (BR-PT04) chứ không phải điểm tích tại chi nhánh này; endpoint trả
    // `meta.scope = brand` để màn hình ghi nhãn đúng.
    Route::get('{customer}/points', [CustomerController::class, 'points'])->name('points');

    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::post('/', [CustomerController::class, 'store'])->name('store');
    Route::get('{customer}', [CustomerController::class, 'show'])->name('show');
    Route::put('{customer}', [CustomerController::class, 'update'])->name('update');
    Route::delete('{customer}', [CustomerController::class, 'destroy'])->name('destroy');
});
