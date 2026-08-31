<?php

use App\Http\Controllers\Api\V1\Shop\TillTenderTypeController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — cashier tender types (admin CRUD).
 *
 * Read returns org-wide (branch_id NULL) + shop-scoped rows for the
 * current shop's org. Mutate only touches shop-scoped rows; org-wide
 * seeded rows are read-only here (403 on edit/delete).
 *
 *   GET    /shops/{shopSlug}/tender-types
 *   POST   /shops/{shopSlug}/tender-types
 *   PATCH  /shops/{shopSlug}/tender-types/{tenderType}
 *   DELETE /shops/{shopSlug}/tender-types/{tenderType}
 */

Route::prefix('tender-types')
    ->name('api.v1.shops.tender-types.')
    ->group(function () {
        Route::get('/', [TillTenderTypeController::class, 'index'])->name('index');
        Route::post('/', [TillTenderTypeController::class, 'store'])->name('store');
        Route::patch('{tenderType}', [TillTenderTypeController::class, 'update'])->name('update');
        Route::delete('{tenderType}', [TillTenderTypeController::class, 'destroy'])->name('destroy');
    });
