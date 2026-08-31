<?php

use App\Http\Controllers\Api\V1\Shop\VoidReasonController;
use Illuminate\Support\Facades\Route;

/*
 * Shop-domain routes — void reasons (plan-051 #1149).
 *
 * Mounted from routes/api.php inside the shops/{shopSlug} prefix.
 * Read-only: the master is edited at HQ (/hq/{brand}/void-reasons);
 * shops only list the active rows for the void-dialog picker.
 */

Route::get('void-reasons', [VoidReasonController::class, 'index'])
    ->name('api.v1.shops.void_reasons.index');
