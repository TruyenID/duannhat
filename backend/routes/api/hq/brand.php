<?php

use App\Http\Controllers\Api\V1\HQ\BrandInfoController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Brand info (single-row endpoint used by HQ layout for slug validation)
// =========================================================================

Route::get('/', [BrandInfoController::class, 'show'])->name('api.v1.hq.show');
