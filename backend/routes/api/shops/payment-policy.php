<?php

use App\Http\Controllers\Api\V1\Shop\EffectivePaymentOptionsController;
use App\Http\Controllers\Api\V1\Shop\ShopPaymentConfigurationController;
use App\Http\Controllers\Api\V1\Shop\ShopPayPaySettingsController;
use Illuminate\Support\Facades\Route;

/*
 * Shop payment policy configuration and effective options (Plan 047 T5.3/T5.4).
 */

// plan-054 T5.6 — the shop-level PayPay off switch. Lives here rather than in
// shop.php beside the other settings/* routes because it writes the same
// `shop_payment_options` policy row the endpoints below do; see the controller
// docblock for why it is not just the generic payment-options PATCH.
Route::prefix('settings/paypay')->name('api.v1.shops.settings.paypay.')->group(function () {
    Route::get('/', [ShopPayPaySettingsController::class, 'show'])->name('show');
    Route::patch('/', [ShopPayPaySettingsController::class, 'update'])->name('update');
});

Route::get('payment-configuration', [ShopPaymentConfigurationController::class, 'show'])
    ->name('api.v1.shops.payment-configuration.show');

Route::post('payment-gateways', [ShopPaymentConfigurationController::class, 'storeGateway'])
    ->name('api.v1.shops.payment-gateways.store');
Route::patch('payment-gateways/{connection}', [ShopPaymentConfigurationController::class, 'updateGateway'])
    ->name('api.v1.shops.payment-gateways.update');

Route::patch('payment-options/{option}', [ShopPaymentConfigurationController::class, 'updateOption'])
    ->name('api.v1.shops.payment-options.update');

Route::get('effective-payment-options', [EffectivePaymentOptionsController::class, 'index'])
    ->name('api.v1.shops.effective-payment-options.index');

Route::prefix('devices/{device}/payment-options')->name('api.v1.shops.devices.payment-options.')->group(function () {
    Route::get('/', [EffectivePaymentOptionsController::class, 'deviceIndex'])->name('index');
    Route::patch('/', [EffectivePaymentOptionsController::class, 'deviceUpdate'])->name('update');
});
