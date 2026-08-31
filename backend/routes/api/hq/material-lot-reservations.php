<?php

use App\Http\Controllers\Api\V1\HQ\MaterialLotReservationController;
use Illuminate\Support\Facades\Route;

Route::prefix('material-lot-reservations')->name('api.v1.hq.material-lot-reservations.')->group(function () {
    Route::get('/', [MaterialLotReservationController::class, 'index'])->name('index');
    Route::post('/', [MaterialLotReservationController::class, 'store'])->name('store');
    Route::get('{reservation}', [MaterialLotReservationController::class, 'show'])->name('show');
    Route::post('{reservation}/release', [MaterialLotReservationController::class, 'release'])->name('release');
    Route::post('{reservation}/renew', [MaterialLotReservationController::class, 'renew'])->name('renew');
});
