<?php

use App\Http\Controllers\Api\V1\Kds\KdsController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  KDS Device Endpoints — authenticated via device.auth:kds middleware
//  Kitchen Display System — bếp staff bumping item status through the
//  pending → preparing → ready → served lifecycle.
// =========================================================================

Route::prefix('v1/kds')
    ->middleware(['device.auth:kds', 'throttle:120,1'])
    ->group(function () {
        Route::get('me', [KdsController::class, 'me'])->name('api.v1.kds.me');
        Route::get('orders', [KdsController::class, 'orders'])->name('api.v1.kds.orders');
        Route::patch('orders/{customerOrder}/items/{item}/status', [KdsController::class, 'updateItemStatus'])
            ->name('api.v1.kds.orders.items.status');

        // Phase 3 — operation-oriented endpoints (gen-2)
        Route::post('orders/{customerOrder}/items/{item}/mark-preparing', [KdsController::class, 'markPreparing'])
            ->name('api.v1.kds.orders.items.mark-preparing');
        Route::post('orders/{customerOrder}/items/{item}/mark-ready', [KdsController::class, 'markReady'])
            ->name('api.v1.kds.orders.items.mark-ready');
        Route::post('orders/{customerOrder}/items/{item}/mark-served', [KdsController::class, 'markServed'])
            ->name('api.v1.kds.orders.items.mark-served');
        Route::post('orders/{customerOrder}/items/{item}/revert', [KdsController::class, 'revert'])
            ->name('api.v1.kds.orders.items.revert');
        Route::post('orders/{customerOrder}/bump-all', [KdsController::class, 'bumpAll'])
            ->name('api.v1.kds.orders.bump-all');
    });
