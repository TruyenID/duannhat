<?php

use App\Http\Controllers\Api\V1\HQ\RecallController;
use Illuminate\Support\Facades\Route;

// Plan-017 Tier 1.A — recall execution. HQ-only routes; role gating
// happens in RecallPolicy + the controller authorize() calls.
Route::prefix('recalls')->name('api.v1.hq.recalls.')->group(function () {
    Route::post('preview', [RecallController::class, 'preview'])->name('preview');
    Route::post('drill', [RecallController::class, 'drill'])->name('drill');
    Route::get('drills', [RecallController::class, 'drillHistory'])->name('drills');
    Route::get('/', [RecallController::class, 'index'])->name('index');
    Route::post('/', [RecallController::class, 'store'])->name('store');
    Route::get('{recall}', [RecallController::class, 'show'])->name('show');
    Route::get('{recall}/report', [RecallController::class, 'report'])->name('report');
    Route::post('{recall}/complete', [RecallController::class, 'complete'])->name('complete');
    Route::post('{recall}/cancel', [RecallController::class, 'cancel'])->name('cancel');
    Route::post('{recall}/notify', [RecallController::class, 'notify'])->name('notify');
});
