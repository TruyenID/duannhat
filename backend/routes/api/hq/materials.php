<?php

use App\Http\Controllers\Api\V1\HQ\MaterialController;
use App\Http\Controllers\Api\V1\HQ\MaterialUnitController;
use App\Http\Controllers\Api\V1\HQ\RecipeController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Materials
// =========================================================================

Route::get('materials/import/template', [MaterialController::class, 'importTemplate'])->name('api.v1.materials.importTemplate');
Route::post('materials/import', [MaterialController::class, 'importCsv'])->name('api.v1.materials.import');
Route::get('materials/export', [MaterialController::class, 'exportCsv'])->name('api.v1.materials.export');
Route::get('materials/lookup', [MaterialController::class, 'lookup'])->name('api.v1.materials.lookup');
Route::get('materials/dropdown', [MaterialController::class, 'lookup'])->name('api.v1.materials.dropdown'); // @deprecated — use /lookup
Route::post('materials/bulk-delete', [MaterialController::class, 'bulkDelete'])->name('api.v1.materials.bulkDelete');
Route::get('materials/{material}/check-usage', [MaterialController::class, 'checkUsage'])->name('api.v1.materials.checkUsage');
Route::post('materials/{material}/restore', [MaterialController::class, 'restore'])->name('api.v1.materials.restore');
Route::apiResource('materials', MaterialController::class)->names('api.v1.materials');

// Plan-017: nested unit conversions per material.
Route::prefix('materials/{material}/units')->name('api.v1.materials.units.')->group(function () {
    Route::get('/', [MaterialUnitController::class, 'index'])->name('index');
    Route::post('/', [MaterialUnitController::class, 'store'])->name('store');
    Route::put('{unit}', [MaterialUnitController::class, 'update'])->name('update');
    Route::delete('{unit}', [MaterialUnitController::class, 'destroy'])->name('destroy');
});

// =========================================================================
//  Recipes
// =========================================================================

Route::get('recipes/import/template', [RecipeController::class, 'importTemplate'])->name('api.v1.recipes.importTemplate');
Route::post('recipes/import', [RecipeController::class, 'importCsv'])->name('api.v1.recipes.import');
Route::get('recipes/export', [RecipeController::class, 'exportCsv'])->name('api.v1.recipes.export');
Route::get('recipes/lookup', [RecipeController::class, 'lookup'])->name('api.v1.recipes.lookup');
Route::get('recipes/dropdown', [RecipeController::class, 'lookup'])->name('api.v1.recipes.dropdown'); // @deprecated — use /lookup
Route::post('recipes/bulk-delete', [RecipeController::class, 'bulkDelete'])->name('api.v1.recipes.bulkDelete');
Route::post('recipes/{recipe}/restore', [RecipeController::class, 'restore'])->name('api.v1.recipes.restore');
Route::post('recipes/{recipe}/submit-for-approval', [RecipeController::class, 'submitForApproval'])->name('api.v1.recipes.submitForApproval');
Route::post('recipes/{recipe}/approve', [RecipeController::class, 'approve'])->name('api.v1.recipes.approve');
Route::post('recipes/{recipe}/reject', [RecipeController::class, 'reject'])->name('api.v1.recipes.reject');
Route::apiResource('recipes', RecipeController::class)->names('api.v1.recipes');
