<?php

use App\Http\Controllers\Api\V1\HQ\ShopController;
use Illuminate\Support\Facades\Route;

Route::post('shops/bulk-delete', [ShopController::class, 'bulkDelete'])->name('api.v1.hq.shops.bulk_delete');
Route::post('shops/upload-image', [ShopController::class, 'uploadImage'])->name('api.v1.hq.shops.upload_image');
Route::post('shops', [ShopController::class, 'store'])->name('api.v1.hq.shops.store');
Route::put('shops/{shop}', [ShopController::class, 'update'])->name('api.v1.hq.shops.update');
Route::delete('shops/{shop}', [ShopController::class, 'destroy'])->name('api.v1.hq.shops.destroy');
Route::post('shops/{shop}/toggle-status', [ShopController::class, 'toggleStatus'])->name('api.v1.hq.shops.toggle_status');
Route::post('shops/{shop}/restore', [ShopController::class, 'restore'])->name('api.v1.hq.shops.restore');
