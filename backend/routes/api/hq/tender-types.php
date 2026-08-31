<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HQ\TenderTypeController;
use Illuminate\Support\Facades\Route;

/*
 * #1881 — từ vựng tender cấp tổ chức.
 *
 * KHÔNG có route đổi `tender_key`: nó bất biến theo thiết kế đã chốt, và một
 * endpoint "rename" tồn tại sẽ là lời mời gọi dùng nó.
 */

Route::get('tender-types', [TenderTypeController::class, 'index'])
    ->name('tender-types.index');

Route::post('tender-types', [TenderTypeController::class, 'store'])
    ->name('tender-types.store');

Route::patch('tender-types/{id}', [TenderTypeController::class, 'update'])
    ->name('tender-types.update');

Route::delete('tender-types/{id}', [TenderTypeController::class, 'destroy'])
    ->name('tender-types.destroy');
