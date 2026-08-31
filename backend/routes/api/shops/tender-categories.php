<?php

use App\Http\Controllers\Api\V1\Shop\TillTenderCategoryController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — tender categories (admin CRUD).
 *
 * GET reads org-wide system rows + shop-scoped custom rows for the
 * current shop's org. Mutate only touches shop-scoped non-system rows;
 * system rows are read-only here (403 on edit/delete).
 *
 *   GET    /shops/{shopSlug}/tender-categories
 *   POST   /shops/{shopSlug}/tender-categories
 *   PATCH  /shops/{shopSlug}/tender-categories/{tenderCategory}
 *   DELETE /shops/{shopSlug}/tender-categories/{tenderCategory}
 */

Route::prefix('tender-categories')
    ->name('api.v1.shops.tender-categories.')
    ->group(function () {
        Route::get('/', [TillTenderCategoryController::class, 'index'])->name('index');
        Route::post('/', [TillTenderCategoryController::class, 'store'])->name('store');
        Route::patch('{tenderCategory}', [TillTenderCategoryController::class, 'update'])->name('update');
        Route::delete('{tenderCategory}', [TillTenderCategoryController::class, 'destroy'])->name('destroy');
    });
