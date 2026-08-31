<?php

use App\Http\Controllers\Api\V1\Tms\TmsController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  TMS Device Endpoints — authenticated via device.auth middleware
// =========================================================================

// Read endpoints — accessible by TMS, Kiosk, and Workstation devices.
// Workstation pulls /me, /zones, /tables to populate its local SQLite replica
// (Phase 1.5 sync DOWN). Write endpoints stay TMS-only below.
Route::prefix('v1/tms')
    ->middleware('device.auth:tms,kiosk,workstation')
    ->group(function () {
        Route::get('me', [TmsController::class, 'me'])->name('api.v1.tms.me');
        Route::get('zones', [TmsController::class, 'zones'])->name('api.v1.tms.zones');
        Route::get('tables', [TmsController::class, 'tables'])->name('api.v1.tms.tables');
    });

// Write endpoints — TMS only
Route::prefix('v1/tms')
    ->middleware('device.auth:tms')
    ->group(function () {
        Route::post('tables/{table}/status', [TmsController::class, 'changeTableStatus'])->name('api.v1.tms.tables.status');
        Route::delete('tables/{table}/call', [TmsController::class, 'clearCall'])->name('api.v1.tms.tables.clear-call');
    });
