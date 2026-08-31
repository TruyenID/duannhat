<?php

use App\Http\Controllers\Api\V1\Shop\PrintJobController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — the print LEDGER (plan-052 M2 / T2.2, #1166).
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 *
 * Read-mostly by design. `resolve` is the only write, and it records a HUMAN's
 * decision about a job the pipeline could not settle — it is not a retry and
 * cannot become one (DESIGN §1b, RISKS PR1). Reprinting a money document is a
 * separate action behind the reprint gate:
 * POST /api/v1/pos/print-jobs/reprint-authorization.
 */

Route::prefix('print-jobs')->name('api.v1.shops.print-jobs.')->group(function () {
    Route::get('/', [PrintJobController::class, 'index'])->name('index');
    Route::get('{printJob}', [PrintJobController::class, 'show'])->name('show');
    Route::post('{printJob}/resolve', [PrintJobController::class, 'resolve'])->name('resolve');
});
