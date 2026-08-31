<?php

use App\Http\Controllers\Api\V1\HQ\MenuPromotionController;
use Illuminate\Support\Facades\Route;

/*
 * HQ — read-only cross-shop promotions list (plan-019, S11).
 *
 * Mounted from routes/api.php inside the hq/{brandSlug} prefix.
 */

Route::prefix('promotions')->name('api.v1.hq.promotions.')->group(function () {
    Route::get('/', [MenuPromotionController::class, 'index'])->name('index');
});
