<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

/**
 * #962 — CustomerEngagement nhờ Pricing đúc một coupon CÁ NHÂN khi khách
 * đổi điểm.
 *
 * `coupons` thuộc Pricing; `CustomerPointService` (điểm tích luỹ, #1441) thuộc
 * CustomerEngagement và tự `Coupon::create()` — cạnh duy nhất còn lại giữa hai
 * module. Chính docblock cũ của nó đã ghi rõ tấm này KHÁC đường HQ
 * (`CouponService::create` ghi audit dưới tên một user console, chạy guard
 * locked-field, đồng bộ pivot chi nhánh); nên nó là một PHÉP ĐÚC RIÊNG của
 * Pricing, không phải một biến thể của đường cũ.
 *
 * Sinh mã, chính sách hạn mức (`1 tấm / 1 người`) và trạng thái phát hành nằm ở
 * PHÍA HIỆN THỰC — người gọi chỉ mô tả điều khoản giảm giá.
 */
interface PersonalCouponMinting
{
    public function mint(PersonalCouponSpec $spec): MintedCoupon;
}
