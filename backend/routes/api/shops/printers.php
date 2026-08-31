<?php

use App\Http\Controllers\Api\V1\Shop\PrinterController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — physical ESC/POS printer configuration.
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 * Shop staff configure the printers physically installed in their shop.
 *
 * Cloud stores the config; the workstation pulls it down and does the actual
 * printing (see /api/v1/workstation/printers). A Cloud outage must never stop
 * a shop from printing — the workstation keeps a local cache.
 */

// =========================================================================
//  Printers
// =========================================================================

Route::prefix('printers')->name('api.v1.shops.printers.')->group(function () {
    Route::post('{printer}/rotate-print-token', [PrinterController::class, 'rotatePrintToken'])->name('rotate-print-token');
    Route::post('{printer}/restore', [PrinterController::class, 'restore'])->name('restore')->withTrashed();

    Route::get('/', [PrinterController::class, 'index'])->name('index');
    Route::post('/', [PrinterController::class, 'store'])->name('store');
    Route::get('{printer}', [PrinterController::class, 'show'])->name('show');
    Route::put('{printer}', [PrinterController::class, 'update'])->name('update');
    Route::patch('{printer}', [PrinterController::class, 'update'])->name('patch');
    Route::delete('{printer}', [PrinterController::class, 'destroy'])->name('destroy');
});
