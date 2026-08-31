<?php

use App\Http\Controllers\Api\V1\HQ\PaymentGatewayConnectionController;
use App\Http\Controllers\Api\V1\HQ\PaymentGatewayCoverageController;
use App\Http\Controllers\Api\V1\HQ\PaymentGatewayOptionPolicyController;
use App\Http\Controllers\Api\V1\HQ\PaymentReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('payment-readiness', [PaymentReadinessController::class, 'show'])
    ->name('api.v1.hq.payment-readiness.show');

Route::get('payment-gateways', [PaymentGatewayConnectionController::class, 'index'])
    ->name('api.v1.hq.payment-gateways.index');

Route::post('payment-gateways', [PaymentGatewayConnectionController::class, 'store'])
    ->name('api.v1.hq.payment-gateways.store');

Route::get('payment-gateways/{connection}', [PaymentGatewayConnectionController::class, 'show'])
    ->name('api.v1.hq.payment-gateways.show');

Route::patch('payment-gateways/{connection}', [PaymentGatewayConnectionController::class, 'update'])
    ->name('api.v1.hq.payment-gateways.update');

Route::post('payment-gateways/{connection}/validate', [PaymentGatewayConnectionController::class, 'validateConnection'])
    ->name('api.v1.hq.payment-gateways.validate');

Route::post('payment-gateways/{connection}/rotate', [PaymentGatewayConnectionController::class, 'rotate'])
    ->name('api.v1.hq.payment-gateways.rotate');

Route::get('payment-gateways/{connection}/disconnect-impact', [PaymentGatewayConnectionController::class, 'disconnectImpact'])
    ->name('api.v1.hq.payment-gateways.disconnect-impact');

Route::delete('payment-gateways/{connection}', [PaymentGatewayConnectionController::class, 'destroy'])
    ->name('api.v1.hq.payment-gateways.destroy');

Route::get('payment-options', [PaymentGatewayOptionPolicyController::class, 'index'])
    ->name('api.v1.hq.payment-options.index');

Route::patch('payment-options/{option}', [PaymentGatewayOptionPolicyController::class, 'update'])
    ->name('api.v1.hq.payment-options.update');

Route::get('payment-coverage', [PaymentGatewayCoverageController::class, 'index'])
    ->name('api.v1.hq.payment-coverage.index');
