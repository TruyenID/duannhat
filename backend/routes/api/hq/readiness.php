<?php

use App\Http\Controllers\Api\V1\HQ\BrandReadinessController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Readiness (#2344) — brand + shop baseline checklist, READ ONLY.
//  Cùng phép đo với `php artisan provisioning:reconcile --dry-run`.
// =========================================================================

Route::get('/readiness', [BrandReadinessController::class, 'show'])->name('api.v1.hq.readiness');
