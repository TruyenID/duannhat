<?php

use App\Http\Controllers\Api\V1\Shop\DeviceController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — device management.
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 * Shop staff can manage devices registered to their branch.
 */

// =========================================================================
//  Devices
// =========================================================================

Route::prefix('devices')->name('api.v1.shops.devices.')->group(function () {
    Route::post('{device}/regenerate-pairing', [DeviceController::class, 'regeneratePairingCode'])->name('regeneratePairing');
    Route::post('{device}/revoke', [DeviceController::class, 'revoke'])->name('revoke');
    Route::post('{device}/restore', [DeviceController::class, 'restore'])->name('restore')->withTrashed();

    Route::get('/', [DeviceController::class, 'index'])->name('index');
    Route::post('/', [DeviceController::class, 'store'])->name('store');
    Route::get('{device}', [DeviceController::class, 'show'])->name('show');
    Route::put('{device}', [DeviceController::class, 'update'])->name('update');
    Route::delete('{device}', [DeviceController::class, 'destroy'])->name('destroy');
});
