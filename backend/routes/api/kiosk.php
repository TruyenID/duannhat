<?php

use App\Http\Controllers\Api\V1\Kiosk\KioskController;
use App\Http\Controllers\Api\V1\Kiosk\KioskPrinterReplicaController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Kiosk Device Endpoints — authenticated via device.auth middleware
// =========================================================================

Route::prefix('v1/kiosk')
    ->middleware('device.auth:kiosk,self_regi')
    ->group(function () {
        Route::get('me', [KioskController::class, 'me'])->name('api.v1.kiosk.me');
        Route::get('orders', [KioskController::class, 'orders'])->name('api.v1.kiosk.orders');

        // Cloud-owned printer config, read-only. The kiosk mirrors this instead
        // of assuming the workstation knows its own printers — the first step of
        // going cloud-first (kiosk-app#44). Device-keyed limiter so co-located
        // kiosks don't share a bucket. Actual byte-pushing to the ESC/POS
        // printer still goes through the workstation LAN gateway.
        Route::get('printers', [KioskPrinterReplicaController::class, 'index'])
            ->middleware('throttle:kiosk-reads')
            ->name('api.v1.kiosk.printers.index');

        Route::get('effective-payment-options', [KioskController::class, 'effectivePaymentOptions'])
            ->name('api.v1.kiosk.effective-payment-options.index');

        Route::post('payments', [KioskController::class, 'pay'])
            // Named limiter (60/min, keyed by device id) instead of throttle:10,1
            // so an offline-batch flush on reconnect isn't 429'd. See
            // AppServiceProvider::boot() `kiosk-payments`.
            ->middleware('throttle:kiosk-payments')
            ->name('api.v1.kiosk.payments.pay');

        Route::get('payments/{id}/status', [KioskController::class, 'paymentStatus'])
            ->middleware('throttle:30,1')
            ->name('api.v1.kiosk.payments.status');

        Route::post('payments/{payment}/confirm', [KioskController::class, 'confirmPayment'])
            ->name('api.v1.kiosk.payments.confirm');

        Route::post('payments/{payment}/fail', [KioskController::class, 'failPayment'])
            ->name('api.v1.kiosk.payments.fail');

        // Plan 033 — by-items split preview (read-only).
        Route::get('orders/{customerOrder}/split-by-items/preview', [KioskController::class, 'splitByItemsPreview'])
            ->name('api.v1.kiosk.orders.split-by-items.preview');

        // PCI-DSS Req 10.2: kiosk emits structured lifecycle events so a
        // device crash mid-payment leaves a trail beyond cloud's own
        // payment-record state. Named `kiosk-audit` limiter keys by
        // device_id (not client IP) so two kiosks behind a single NAT
        // each get their own 60/min budget. ~9 events per transaction
        // = ~6× headroom for peak.
        Route::post('audit-logs', [KioskController::class, 'auditLog'])
            ->middleware('throttle:kiosk-audit')
            ->name('api.v1.kiosk.audit-logs.store');
    });
