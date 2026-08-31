<?php

use App\Http\Controllers\Api\V1\Shop\PeripheralDeviceController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — peripheral-device management.
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 * Branch staff can manage peripherals (printers, payment terminals, cash
 * changers, and POS/workstation/kiosk aliases used for role scoping).
 */

// =========================================================================
//  Peripheral devices
// =========================================================================

Route::prefix('peripheral-devices')->name('api.v1.shops.peripheral-devices.')->group(function () {
    Route::post('{peripheral_device}/restore', [PeripheralDeviceController::class, 'restore'])->name('restore')->withTrashed();

    Route::get('/', [PeripheralDeviceController::class, 'index'])->name('index');
    Route::post('/', [PeripheralDeviceController::class, 'store'])->name('store');
    Route::get('{peripheral_device}', [PeripheralDeviceController::class, 'show'])->name('show');
    Route::put('{peripheral_device}', [PeripheralDeviceController::class, 'update'])->name('update');
    Route::delete('{peripheral_device}', [PeripheralDeviceController::class, 'destroy'])->name('destroy');
});
