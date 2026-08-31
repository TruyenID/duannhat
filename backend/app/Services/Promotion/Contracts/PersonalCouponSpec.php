<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

/**
 * #962 — mô tả tấm coupon CÁ NHÂN mà CustomerEngagement muốn Pricing
 * đúc, dưới dạng dữ liệu thuần.
 *
 * Vì sao không truyền `PointReward` (model của CustomerEngagement) rồi để Pricing
 * tự đọc: hợp đồng công bố chỉ được phụ thuộc hai kernel, nên một chữ ký mang
 * model sẽ chỉ ĐẢO CHIỀU cạnh chứ không gỡ (cùng bài học với
 * `OrderLineStockDeduction`, #1595). Bên nào sở hữu `point_rewards` thì bên đó
 * đọc nó — rồi trao sang cái đã đọc được.
 *
 * Các trường ở đây là ĐIỀU KHOẢN giảm giá, KHÔNG phải chính sách phát hành:
 * `usage_limit_total = 1`, `usage_limit_per_customer = 1`, `status = draft` và
 * cách sinh mã đều là quyết định của Pricing và ở lại bên đó.
 */
final class PersonalCouponSpec
{
    /**
     * @param  array<string, string>  $namesByLocale  tên coupon theo `ja`/`en`/`vi`
     */
    public function __construct(
        public readonly string $customerId,
        public readonly string $pointRewardId,
        public readonly ?string $organizationId,
        public readonly ?string $brandId,
        public readonly ?string $discountType,
        public readonly ?string $discountValue,
        public readonly ?string $maxDiscountCap,
        public readonly ?string $minOrderSubtotal,
        public readonly int $validDays,
        public readonly array $namesByLocale = [],
    ) {}
}
