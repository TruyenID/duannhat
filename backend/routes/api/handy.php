<?php

use App\Http\Controllers\Api\V1\Handy\HandyController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Handy Device Endpoints — authenticated via device.auth middleware
//  Device type: handy (POS handheld — staff order-taking app)
// =========================================================================

Route::prefix('v1/handy')
    ->middleware('device.auth:handy,pos')
    ->group(function () {
        Route::get('me', [HandyController::class, 'me'])->name('api.v1.handy.me');
        Route::get('tables', [HandyController::class, 'tables'])->name('api.v1.handy.tables');
        Route::get('menus/by-day/{dayOfWeek}', [HandyController::class, 'menusByDay'])->name('api.v1.handy.menus.by-day');
        Route::get('menus/{menu}/products', [HandyController::class, 'menuProducts'])->name('api.v1.handy.menus.products');
        Route::get('settings/order', [HandyController::class, 'orderSettings'])->name('api.v1.handy.settings.order');

        // Orders
        Route::get('orders', [HandyController::class, 'orders'])->name('api.v1.handy.orders.index');
        Route::post('orders', [HandyController::class, 'createOrder'])->name('api.v1.handy.orders.store');
        Route::get('orders/{order}', [HandyController::class, 'showOrder'])->name('api.v1.handy.orders.show');
        Route::delete('orders/{order}', [HandyController::class, 'voidOrder'])->name('api.v1.handy.orders.void');
        Route::post('orders/{order}/items', [HandyController::class, 'addItems'])->name('api.v1.handy.orders.items.store');
        Route::patch('orders/{order}/items/{item}', [HandyController::class, 'updateItem'])->name('api.v1.handy.orders.items.update');
        // #876 — direct payment at the table, gated by the per-shop
        // handy_allow_direct_payment toggle (default OFF → 403).
        Route::post('orders/{order}/payments', [HandyController::class, 'createPayment'])->name('api.v1.handy.orders.payments.store');
        Route::delete('orders/{order}/items/{item}', [HandyController::class, 'removeItem'])->name('api.v1.handy.orders.items.destroy');
    });
