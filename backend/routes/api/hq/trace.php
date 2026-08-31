<?php

use App\Http\Controllers\Api\V1\HQ\TraceController;
use Illuminate\Support\Facades\Route;

// Rate-limited 30 req/min — trace queries hit recursive joins and hot supplier
// lots can fan out to thousands of children, so a cap protects the DB.
//
// This bucket is now keyed PER USER: bootstrap/app.php prioritises SsoAuthenticate
// ahead of ThrottleRequests so `$request->user()` is resolved before the limiter
// runs. Previously the user was still null here, so numeric `throttle:30,1` fell
// back to client IP (see ThrottleRequests::resolveRequestSignature) — and on a
// shared IP the browser, kiosk, customer-web and the workstation-app (allowed
// throttle:300,1) all drained this same counter, starving the trace tool into
// permanent 429s even when nobody was tracing. Per-user keying isolates it.
Route::middleware('throttle:30,1')
    ->prefix('trace')
    ->name('api.v1.hq.trace.')
    ->group(function () {
        Route::get('lot/{lot}', [TraceController::class, 'lot'])->name('lot');
        Route::get('customer-order/{order}', [TraceController::class, 'customerOrder'])->name('customerOrder');
    });
