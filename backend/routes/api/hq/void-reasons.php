<?php

use App\Http\Controllers\Api\V1\HQ\VoidReasonController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  HQ - Void Reasons (plan-051 #1149 — brand-scoped void-reason master)
//
//  index/store/update only — deactivation is update {is_active: false};
//  historical order lines reference reasons by id, so there is NO delete.
// =========================================================================

Route::prefix('void-reasons')->name('api.v1.hq.void_reasons.')->group(function () {
    Route::get('/', [VoidReasonController::class, 'index'])->name('index');
    Route::post('/', [VoidReasonController::class, 'store'])->name('store');
    Route::patch('{voidReason}', [VoidReasonController::class, 'update'])->name('update');
});
