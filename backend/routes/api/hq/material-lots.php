<?php

use App\Http\Controllers\Api\V1\HQ\MaterialLotController;
use Illuminate\Support\Facades\Route;

Route::prefix('material-lots')->name('api.v1.hq.material-lots.')->group(function () {
    Route::get('/', [MaterialLotController::class, 'index'])->name('index');
    Route::post('bulk-delete', [MaterialLotController::class, 'bulkDelete'])->name('bulk-delete');
    Route::get('{lot}', [MaterialLotController::class, 'show'])->name('show');
    Route::post('{lot}/quarantine', [MaterialLotController::class, 'quarantine'])->name('quarantine');
    Route::post('{lot}/release', [MaterialLotController::class, 'release'])->name('release');
    Route::post('{lot}/dispose', [MaterialLotController::class, 'dispose'])->name('dispose');
    Route::get('{lot}/timeline', [MaterialLotController::class, 'timeline'])->name('timeline');
});
