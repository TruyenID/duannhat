<?php

use App\Http\Controllers\Api\V1\HQ\CustomerController;
use Illuminate\Support\Facades\Route;

/*
 * HQ-domain routes — customer read-only view.
 *
 * Mounted from routes/api.php inside the hq/{brandSlug} prefix.
 * Brand admins can view customers across all branches.
 */

Route::prefix('customers')->name('api.v1.hq.customers.')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::get('{customer}', [CustomerController::class, 'show'])->name('show');

    // #1700 — số dư + sổ điểm + mã đã đổi. Tách khỏi `show` vì sổ có phân
    // trang riêng: gộp vào thì mở chi tiết một khách lâu năm sẽ kéo cả nghìn
    // bút toán chỉ để hiện năm dòng đầu.
    Route::get('{customer}/points', [CustomerController::class, 'points'])->name('points');
});
