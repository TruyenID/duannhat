<?php

use App\Http\Controllers\Api\V1\Branch\BranchMenuScheduleController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Shop Menus > Schedule Overrides
//  Shop managers can override start_time/end_time per schedule window and
//  activate/pause each window (is_active) to control customer-facing visibility.
//  Scoped: {schedule} must belong to {menu}.
// =========================================================================

Route::prefix('menus/{menu}/schedules')->name('api.v1.shop.menus.schedules.')->group(function () {
    Route::get('/', [BranchMenuScheduleController::class, 'index'])->name('index');
    Route::put('{schedule}/active', [BranchMenuScheduleController::class, 'setActive'])->name('active.set');
    Route::put('{schedule}/override', [BranchMenuScheduleController::class, 'upsertOverride'])->name('override.upsert');
    Route::delete('{schedule}/override', [BranchMenuScheduleController::class, 'destroyOverride'])->name('override.destroy');
});
