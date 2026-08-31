<?php

use App\Http\Controllers\Api\V1\Printing\CloudPrntController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Star CloudPRNT — plan-052 M4 / plan-053 T5.4 (#1171)
|--------------------------------------------------------------------------
|
| Called by a THERMAL PRINTER, not by any of our clients. Deliberately outside
| every auth ring: a Star mC-Print cannot pair through `POST /devices/pair`,
| cannot hold a Sanctum token and cannot refresh anything — the protocol's own
| answer is a long-lived per-printer secret in the URL (`printers.print_token`,
| P-16: ≥32 bytes, rotatable, revoke = 401 at the next poll).
|
| Kept in its own file, and NOT inside `routes/api/{pos,shops,workstation}.php`,
| for the reason those files exist at all: each of them wraps its group in an
| auth middleware. A printer route living in one of them survives exactly until
| someone tightens that group, and the symptom would be a shop whose receipts
| silently stop printing.
|
| One URL, three verbs (Star's, not ours):
|
|   POST   the printer polls and reports status → {jobReady, mediaTypes, …}
|   GET    the printer downloads job bytes      → application/vnd.star.starprnt
|   DELETE the printer confirms the outcome     → ?code=200%20OK
|
| The `{printerToken}` segment is the credential, so it must not be logged with
| the URL. It is matched loosely (no `where`) because the token format is ours
| to change and a pattern here would fail closed on rotation to a new alphabet
| — an already-issued token would 404 rather than 401, which reads as "the
| feature is gone" instead of "your credential is bad".
*/

Route::prefix('v1/print/cloudprnt')
    ->middleware('throttle:cloudprnt')
    ->name('api.v1.print.cloudprnt.')
    ->group(function () {
        Route::post('{printerToken}', [CloudPrntController::class, 'poll'])->name('poll');
        Route::get('{printerToken}', [CloudPrntController::class, 'fetch'])->name('fetch');
        Route::delete('{printerToken}', [CloudPrntController::class, 'confirm'])->name('confirm');
    });
