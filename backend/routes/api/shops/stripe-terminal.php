<?php

use App\Http\Controllers\Api\V1\Shop\StripeTerminalReaderController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — Stripe Terminal reader registry (#1088).
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix (SSO).
 * Fails closed with STRIPE_TERMINAL_DISABLED until
 * payments.stripe_terminal.enabled is turned on (certification posture).
 */

Route::prefix('stripe-terminal')->name('api.v1.shops.stripe-terminal.')->group(function () {
    Route::get('readers', [StripeTerminalReaderController::class, 'index'])->name('readers.index');
    Route::post('readers', [StripeTerminalReaderController::class, 'store'])->name('readers.store');
});
