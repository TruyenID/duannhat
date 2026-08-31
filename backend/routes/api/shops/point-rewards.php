<?php

use App\Http\Controllers\Api\V1\Shop\PointRewardController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  Shop — phần thưởng đổi điểm (#1514)
//
//  CHỈ ĐỌC + MỘT CÔNG TẮC. Không có store/update/destroy ở đây, và đó là chủ
//  ý: phần thưởng thuộc brand, coupon nó mint ra tiêu được ở mọi chi nhánh —
//  để một cửa hàng đặt giá điểm là để cửa hàng đó phát hành giá trị cho cả
//  chuỗi.
//
//  `PATCH .../availability` ghi pivot `point_reward_branches` của ĐÚNG chi
//  nhánh trên URL. Không có dòng pivot = còn bật (BR-PRB01), nên bật lại thứ
//  chưa từng tắt vẫn hợp lệ và chỉ để lại một dòng `is_available = true`.
// =========================================================================

Route::prefix('point-rewards')->name('api.v1.shops.point-rewards.')->group(function () {
    Route::get('/', [PointRewardController::class, 'index'])->name('index');

    // #1718 — nhật ký đổi thưởng nhìn từ cửa hàng. Phạm vi vẫn là BRAND (lượt
    // đổi không gắn chi nhánh nào), chỉ khác bản HQ ở đồng hồ tính bộ lọc ngày:
    // cửa hàng biết chính xác chi nhánh mình nên không phải đoán.
    //
    // Đứng trước `{pointReward}` để binding không nuốt "redemptions" như uuid.
    Route::get('redemptions', [PointRewardController::class, 'redemptions'])->name('redemptions');
    Route::patch('{pointReward}/availability', [PointRewardController::class, 'setAvailability'])
        ->name('setAvailability');
});
