<?php

use App\Http\Controllers\Api\V1\HQ\TableTemplateController;
use App\Http\Controllers\Api\V1\HQ\ZoneTemplateController;
use Illuminate\Support\Facades\Route;

/*
 * HQ table-defaults routes — brand-scoped zone/table TEMPLATES (issue #890).
 *
 * These are NOT physical tables: they define the brand's default floor layout.
 * A shop copies them into real zones/tables via
 * POST /api/v1/shops/{shopSlug}/tables/defaults/apply (idempotent by code) and
 * can still create/edit/delete its own tables afterwards.
 *
 * Mounted under /api/v1/hq/{brandSlug}/... (see routes/api.php).
 */

// =========================================================================
//  Zone Templates
// =========================================================================

Route::prefix('zone-templates')->name('api.v1.hq.zoneTemplates.')->group(function () {
    Route::get('lookup', [ZoneTemplateController::class, 'lookup'])->name('lookup');

    Route::get('/', [ZoneTemplateController::class, 'index'])->name('index');
    Route::post('/', [ZoneTemplateController::class, 'store'])->name('store');
    Route::get('{zoneTemplate}', [ZoneTemplateController::class, 'show'])->name('show');
    Route::put('{zoneTemplate}', [ZoneTemplateController::class, 'update'])->name('update');
    Route::delete('{zoneTemplate}', [ZoneTemplateController::class, 'destroy'])->name('destroy');
    Route::post('{zoneTemplate}/restore', [ZoneTemplateController::class, 'restore'])->name('restore');
    Route::post('{zoneTemplate}/toggle-active', [ZoneTemplateController::class, 'toggleActive'])->name('toggleActive');
});

// =========================================================================
//  Table Templates
// =========================================================================

Route::prefix('table-templates')->name('api.v1.hq.tableTemplates.')->group(function () {
    Route::get('/', [TableTemplateController::class, 'index'])->name('index');
    Route::post('/', [TableTemplateController::class, 'store'])->name('store');
    Route::get('{tableTemplate}', [TableTemplateController::class, 'show'])->name('show');
    Route::put('{tableTemplate}', [TableTemplateController::class, 'update'])->name('update');
    Route::delete('{tableTemplate}', [TableTemplateController::class, 'destroy'])->name('destroy');
    Route::post('{tableTemplate}/restore', [TableTemplateController::class, 'restore'])->name('restore');
    Route::post('{tableTemplate}/toggle-active', [TableTemplateController::class, 'toggleActive'])->name('toggleActive');
});
