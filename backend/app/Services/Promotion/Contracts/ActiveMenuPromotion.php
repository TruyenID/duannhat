<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

use App\Omnify\Enums\MenuPromotionStackingModeEnum;

/**
 * #1597 — KẾT QUẢ phân giải khuyến mãi, do Pricing công bố cho Ordering.
 *
 * Ordering đọc đúng **ba trường** khỏi `MenuPromotion` mà `resolveActivePromotion()`
 * trả về — đo bằng cách quét ba chỗ gọi, không đọc lướt:
 *
 *     id ×1 (ghi `applied_promotion_id`) · discount_percent ×2 · stacking_mode ×1
 *
 * Đây là mẫu **tiêu thụ kết quả** (#1609): Ordering nhận *kết quả tính giá*, đúng
 * chiều ADR 0001. Trước bản vá này nó nhận nguyên **model của Pricing**, nên một
 * chiều đúng vẫn bị đếm là nợ — và tệ hơn, mọi trường khác của model đều nằm
 * trong tầm với của chỗ gọi.
 *
 * `MenuPromotionStackingModeEnum` nằm trong `App\Omnify\Enums`, vốn đã `shared`,
 * nên mang nó ở đây không làm mất tư cách công bố.
 */
final readonly class ActiveMenuPromotion
{
    public function __construct(
        public string $id,
        public float $discountPercent,
        public ?MenuPromotionStackingModeEnum $stackingMode,
    ) {}

    /**
     * Khuyến mãi này CẤM đi kèm coupon (Decision B5).
     *
     * Đặt câu hỏi ở đây thay vì để chỗ gọi tự so enum: bản cũ so bằng CHUỖI
     * (`=== 'exclusive_with_coupons'`) trong khi cột đã cast sang enum, nên rào
     * B5 **luôn false** — nó chết âm thầm cho tới khi có người đọc lại. Một
     * method có tên thì không so nhầm kiểu được.
     */
    public function isExclusiveWithCoupons(): bool
    {
        return $this->stackingMode === MenuPromotionStackingModeEnum::ExclusiveWithCoupons;
    }
}
