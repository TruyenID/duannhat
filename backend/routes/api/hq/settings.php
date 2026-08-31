<?php

use App\Http\Controllers\Api\V1\HQ\BrandReverbController;
use App\Http\Controllers\Api\V1\HQ\HqBrandSettingsController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  HQ - Settings
// =========================================================================

Route::prefix('settings/reverb')->name('api.v1.hq.settings.reverb.')->group(function () {
    Route::get('/', [BrandReverbController::class, 'show'])->name('show');
    Route::post('test', [BrandReverbController::class, 'test'])->name('test');
    Route::post('rotate', [BrandReverbController::class, 'rotate'])->name('rotate');
});

Route::prefix('settings/brand')->name('api.v1.hq.settings.brand.')->group(function () {
    Route::get('/', [HqBrandSettingsController::class, 'show'])->name('show');
    Route::patch('/', [HqBrandSettingsController::class, 'update'])->name('update');
});
