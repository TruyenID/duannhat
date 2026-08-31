<?php

use App\Http\Controllers\Api\V1\HQ\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')
    ->name('api.v1.hq.reports.')
    ->group(function () {
        Route::get('supplier-yield', [ReportController::class, 'supplierYield'])->name('supplier-yield');
    });
