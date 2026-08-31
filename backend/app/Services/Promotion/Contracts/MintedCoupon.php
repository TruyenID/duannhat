<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

/**
 * #962 — tấm coupon Pricing vừa đúc, đọc lại từ hàng đã ghi.
 *
 * Đúng bảy trường mà customer-web hiển thị trong ví khách (xem
 * `CustomerPointController::redeem`). Cố ý KHÔNG mang trạng thái, hạn mức lượt
 * dùng hay pivot chi nhánh: đó là ruột của Pricing, và một DTO "đủ mọi cột" là
 * bản sao model đi vòng.
 */
final class MintedCoupon
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly ?string $name,
        public readonly ?string $discountType,
        public readonly ?string $discountValue,
        public readonly ?string $maxDiscountCap,
        public readonly ?string $minOrderSubtotal,
        public readonly ?string $validUntil,
    ) {}
}
