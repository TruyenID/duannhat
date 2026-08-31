<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

use App\Exceptions\CouponException;
use App\Models\Coupon;

/**
 * Mặt tiếp giáp Pricing → Ordering cho coupon (#1581, epic #962).
 *
 * Đây là TOÀN BỘ những gì `App\Services\Order\Coupon\OrderCouponService` cần hỏi
 * ngược lại Pricing — sáu method, đo bằng cách quét thân từng method trong nửa
 * đơn hàng, không đọc lướt:
 *
 *   computeDiscount          ← apply, recomputeDiscountForOrder
 *   resolveCurrencyForBranch ← apply, recomputeDiscountForOrder
 *   snapshotCoupon           ← apply, recordWorkstationRedemption
 *   assertBranchEligible     ← validateForApply
 *   assertCustomerEligible   ← validateForApply
 *   statusValue              ← validateForApply
 *
 * Không thêm gì "cho đủ bộ". `list`/`create`/`update`/`delete` thì nửa đơn hàng
 * chưa bao giờ gọi, và mỗi method thừa ở đây là một lời mời caller sau với tay
 * vào chỗ Pricing không định mở.
 *
 * `Coupon` có mặt trong chữ ký vì việc định giá thật sự cần chính cái coupon đang
 * áp. Đó là phụ thuộc **đúng chiều** — Ordering hỏi Pricing, không phải ngược lại.
 *
 * #962: nửa đơn hàng KHÔNG còn tiêm cổng này nữa. Vì mang `Coupon` trong chữ ký,
 * nó không đủ điều kiện thành hợp đồng công bố (`config/modules.php`, luật 2),
 * nên `OrderCouponService` giờ đi qua `App\Services\Order\Contracts\
 * OrderCouponLedger`. Cổng này vẫn sống, chỉ đổi người tiêu thụ: nó là mặt trong
 * của Pricing mà `EloquentOrderCouponLedger` gọi tới.
 */
interface CouponPricing
{
    /** Số tiền giảm cho một subtotal, đã làm tròn theo bước tiền tệ. */
    public function computeDiscount(Coupon $coupon, float $subtotal, ?string $currencyCode = null): float;

    /** Mã tiền tệ hiệu lực của chi nhánh (từ `ShopOrderSetting`), null nếu chưa cấu hình. */
    public function resolveCurrencyForBranch(?string $branchId): ?string;

    /**
     * Ảnh chụp bất biến của coupon để đóng dấu lên đơn — giá trị LÚC ÁP.
     *
     * @return array<string, mixed>
     */
    public function snapshotCoupon(Coupon $coupon): array;

    /** @throws CouponException coupon không dùng được ở chi nhánh này */
    public function assertBranchEligible(Coupon $coupon, string $branchId): void;

    /** @throws CouponException khách này đã dùng hết lượt */
    public function assertCustomerEligible(Coupon $coupon, ?string $customerId): void;

    /** Trạng thái hiệu lực đã TÍNH (`active` · `paused` · `expired`…), không phải cột thô. */
    public function statusValue(Coupon $coupon): string;
}
