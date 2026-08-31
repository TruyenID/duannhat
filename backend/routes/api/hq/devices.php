<?php

use App\Http\Controllers\Api\V1\HQ\DeviceController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Devices
// =========================================================================

Route::get('devices/lookup', [DeviceController::class, 'lookup'])->name('api.v1.devices.lookup');
Route::post('devices/bulk-delete', [DeviceController::class, 'bulkDelete'])->name('api.v1.devices.bulkDelete');
Route::post('devices/{device}/regenerate-pairing', [DeviceController::class, 'regeneratePairingCode'])->name('api.v1.devices.regeneratePairing');
Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke'])->name('api.v1.devices.revoke');
// #1093 — Ed25519 signing keys: list + single-key revoke (compromise response).
Route::get('devices/{device}/signing-keys', [DeviceController::class, 'signingKeys'])->name('api.v1.devices.signingKeys');
Route::post('devices/{device}/signing-keys/{signingKey}/revoke', [DeviceController::class, 'revokeSigningKey'])->name('api.v1.devices.signingKeys.revoke');
Route::post('devices/{device}/restore', [DeviceController::class, 'restore'])->name('api.v1.devices.restore')->withTrashed();
Route::apiResource('devices', DeviceController::class)->names('api.v1.devices');
