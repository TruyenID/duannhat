<?php

use App\Http\Controllers\Api\V1\Shop\DenominationController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — cash denominations (admin CRUD).
 *
 * Read returns global + org-scoped rows for the shop's organization. Mutate
 * only touches org-scoped rows; globals are read-only (403 on edit).
 *
 *   GET    /shops/{shopSlug}/denominations?currency=JPY
 *   POST   /shops/{shopSlug}/denominations
 *   PATCH  /shops/{shopSlug}/denominations/{denomination}
 *   DELETE /shops/{shopSlug}/denominations/{denomination}
 */

Route::prefix('denominations')
    ->name('api.v1.shops.denominations.')
    ->group(function () {
        Route::get('/', [DenominationController::class, 'index'])->name('index');
        Route::post('/', [DenominationController::class, 'store'])->name('store');
        Route::patch('{denomination}', [DenominationController::class, 'update'])->name('update');
        Route::delete('{denomination}', [DenominationController::class, 'destroy'])->name('destroy');
    });
