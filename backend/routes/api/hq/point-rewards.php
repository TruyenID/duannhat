<?php

use App\Http\Controllers\Api\V1\HQ\PointRewardController;
use Illuminate\Support\Facades\Route;

// =========================================================================
//  HQ — catalog đổi điểm (#1514)
//
//  Trước issue này bảng `point_rewards` không có màn hình nào; thêm một phần
//  thưởng nghĩa là mở tinker trên production.
//
//  Phạm vi BRAND: `brand_id` lấy từ `{brandSlug}` qua `ResolveBrandFromSlug`,
//  không bao giờ từ body. Phần thưởng bắt buộc thuộc một brand (BR-PR01) vì
//  coupon nó mint ra bắt buộc thuộc một brand.
//
//  Bật/tắt theo từng chi nhánh KHÔNG ở đây — xem routes/api/shops/point-rewards.php.
// =========================================================================

Route::prefix('point-rewards')->name('api.v1.hq.point-rewards.')->group(function () {
    Route::get('/', [PointRewardController::class, 'index'])->name('index');
    Route::post('/', [PointRewardController::class, 'store'])->name('store');

    // #1700 — nhật ký đổi thưởng. PHẢI đứng trước `{pointReward}`: route model
    // binding sẽ nuốt "redemptions" như một uuid và trả 404 nếu đảo thứ tự.
    Route::get('redemptions', [PointRewardController::class, 'redemptions'])->name('redemptions');

    Route::get('{pointReward}', [PointRewardController::class, 'show'])->name('show');
    Route::patch('{pointReward}', [PointRewardController::class, 'update'])->name('update');
    Route::delete('{pointReward}', [PointRewardController::class, 'destroy'])->name('destroy');
});
