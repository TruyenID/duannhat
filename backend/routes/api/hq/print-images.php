<?php

use App\Http\Controllers\Api\V1\HQ\PrintImageController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  HQ — Ảnh in (#1957 mảnh B, tầng brand)
//
//  Brand tải logo lên đây rồi publish; mọi máy trạm của brand nhận nó ở lượt
//  kéo kế tiếp, không cần bản phát hành phần mềm nào — hệt như print-templates.
//  Đọc cần `menu.manage`, ghi cần `catalog.approve` (TR-37), xem
//  PrintImageAssetPolicy.
//
//  Tải lên và publish là HAI thao tác, cố ý: `upload` chỉ tạo bản nháp. Gộp làm
//  một thì một lần kéo nhầm tệp sẽ in ngay ở quán trước khi ai kịp nhìn, và
//  không có nút hoàn tác nào vì bản in đã ra khỏi máy.
//
//  Cố ý KHÔNG có delete. Một ảnh đã publish phải còn render được mãi để bản in
//  lại của phiếu cũ là trung thực (TR-28/TR-39). Đường ra là publish bản MỚI.
//
//  `{source}` bị ràng theo allow-list `print_blocks.image.sources` ngay trong
//  controller — không nhận URL, không nhận định danh lạ (TR-21).
// =========================================================================

Route::prefix('print-images')->name('api.v1.hq.print_images.')->group(function () {
    Route::get('/', [PrintImageController::class, 'index'])->name('index');

    Route::prefix('{source}')->group(function () {
        Route::post('/', [PrintImageController::class, 'upload'])->name('upload');
        Route::post('publish', [PrintImageController::class, 'publish'])->name('publish');
    });
});
