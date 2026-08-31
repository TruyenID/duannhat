<?php

use App\Http\Controllers\Api\V1\HQ\EmployeeAdminController;
use Illuminate\Support\Facades\Route;

// Tempo is a read-only mirror. Platform owns employee invite, role changes,
// and removal; do not duplicate those identity mutations here (#2367).
Route::prefix('employees')->name('api.v1.hq.employees.')->group(function () {
    Route::get('/', [EmployeeAdminController::class, 'index'])->name('index');
});
