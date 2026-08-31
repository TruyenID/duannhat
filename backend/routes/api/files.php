<?php

use App\Http\Controllers\Api\V1\FileController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Files (upload, manage)
// =========================================================================

Route::prefix('files')->name('api.v1.files.')->group(function () {
    Route::post('upload', [FileController::class, 'upload'])->name('upload');
    Route::get('{file}', [FileController::class, 'show'])->name('show');
    Route::post('{file}/make-permanent', [FileController::class, 'makePermanent'])->name('makePermanent');
    Route::delete('{file}', [FileController::class, 'destroy'])->name('destroy');
});
