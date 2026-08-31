<?php

use App\Http\Controllers\Api\V1\HQ\Iam\IamMemberController;
use App\Http\Controllers\Api\V1\HQ\Iam\IamPermissionController;
use App\Http\Controllers\Api\V1\HQ\Iam\IamRoleController;
use App\Http\Controllers\Api\V1\HQ\ShopController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  IAM — member management, role assignments, permission matrix
//  All routes are mounted under /api/v1/hq/{brandSlug}/iam/
// =========================================================================

Route::prefix('iam')->group(function () {
    // Members
    Route::get('members', [IamMemberController::class, 'index'])->name('api.v1.hq.iam.members.index');
    Route::get('members/{user}', [IamMemberController::class, 'show'])->name('api.v1.hq.iam.members.show');
    Route::get('members/{user}/permissions', [IamMemberController::class, 'permissions'])->name('api.v1.hq.iam.members.permissions');
    Route::post('members/{user}/assign', [IamMemberController::class, 'assign'])->name('api.v1.hq.iam.members.assign');
    Route::delete('members/{user}/roles/{roleSlug}', [IamMemberController::class, 'revoke'])->name('api.v1.hq.iam.members.revoke');
    Route::patch('members/{user}/deactivate', [IamMemberController::class, 'deactivate'])->name('api.v1.hq.iam.members.deactivate');
    Route::patch('members/{user}/activate', [IamMemberController::class, 'activate'])->name('api.v1.hq.iam.members.activate');
    Route::post('members/{user}/reset-password', [IamMemberController::class, 'resetPassword'])->name('api.v1.hq.iam.members.reset-password');
    Route::get('members/{user}/audit', [IamMemberController::class, 'audit'])->name('api.v1.hq.iam.members.audit');

    // Roles + permission matrix
    Route::get('roles', [IamRoleController::class, 'index'])->name('api.v1.hq.iam.roles.index');
    Route::put('roles/{role}', [IamRoleController::class, 'update'])->name('api.v1.hq.iam.roles.update');

    // Permission catalogue
    Route::get('permissions', [IamPermissionController::class, 'index'])->name('api.v1.hq.iam.permissions.index');

    // Branches — list shops available for scoped role assignment
    Route::get('branches', [ShopController::class, 'index'])->name('api.v1.hq.iam.branches.index');
});
