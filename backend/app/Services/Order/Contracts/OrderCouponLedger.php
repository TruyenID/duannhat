<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

use App\Exceptions\CouponException;
use Carbon\CarbonImmutable;

/**
 * Cổng Ordering → Pricing cho SỔ COUPON (#962, nối tiếp #1581).
 *
 * #1581 đã đảo đúng chiều phụ thuộc (Ordering hỏi Pricing) nhưng dừng ở việc
 * hỏi GIÁ: `OrderCouponService` vẫn tự khoá `Coupon`, tự tăng `times_used`, tự
 * ghi/xoá `CouponRedemption` — ba việc ghi vào bảng của Pricing, làm từ
 * Ordering. Cổng này chuyển nốt phần GHI, nên hai model `Coupon` và
 * `CouponRedemption` (của Pricing) không còn xuất hiện ở nửa đơn hàng.
 *
 * Vì sao đặt ở `App\Services\Order\Contracts` chứ không cạnh `CouponPricing`:
 * `App\Services\Promotion\Contracts` là Pricing, còn namespace này là hợp đồng
 * CÔNG BỐ. Điều đó cũng ép chữ ký sạch model — `CouponPricing` không đủ điều
 * kiện công bố đúng vì nó nhận `Coupon` (xem `config/modules.php`, luật 2).
 *
 * ## Giao dịch là của NGƯỜI GỌI
 *
 * Mọi method có `lock` trong tên khoá hàng trong giao dịch mà caller đã mở, và
 * `OrderCouponService::apply()` dựa vào đúng điều đó để chống oversell. Gọi
 * chúng ngoài `DB::transaction` là mất khoá — không phải lỗi biên dịch, mà là
 * một cái bán quá số lượt.
 */
interface OrderCouponLedger
{
    /**
     * Khoá và đọc coupon theo mã, trong phạm vi một brand.
     *
     * So khớp mã KHÔNG phân biệt hoa thường (đúng như bản cũ). Null = không có.
     */
    public function lockByCode(string $brandId, string $code): ?CouponTerms;

    /**
     * Khoá và đọc coupon theo id; ném `ModelNotFoundException` nếu không còn.
     *
     * Dùng cho đường sync-UP của workstation, nơi coupon vừa được caller tra ra
     * ngay trước đó — biến mất giữa hai câu lệnh là bất thường, không phải một
     * nhánh nghiệp vụ.
     */
    public function lockByIdOrFail(string $couponId): CouponTerms;

    /** Đọc coupon theo id, KHÔNG khoá. Null = không còn. */
    public function find(string $couponId): ?CouponTerms;

    /** Đơn này đã có dòng redemption nào chưa (kể cả đã released)? */
    public function hasRedemptionForOrder(string $orderId): bool;

    /**
     * Xoá cứng những dòng redemption ĐÃ released của đơn.
     *
     * `coupon_redemptions.customer_order_id` là unique, nên một lần release
     * mềm trước đó sẽ chặn lần insert kế tiếp nếu không quét.
     */
    public function purgeReleasedRedemptions(string $orderId): void;

    /**
     * Tăng `times_used` có rào hạn mức.
     *
     * @return bool false = hạn mức đã đầy, KHÔNG tăng. Caller quyết định đó là
     *              lỗi (đường online) hay chỉ là dòng audit (đường offline).
     */
    public function claimUsage(CouponTerms $coupon): bool;

    /**
     * Ghi dòng redemption, kèm ảnh chụp coupon do Pricing tự dựng.
     *
     * @return bool false = một writer song song đã ghi trước (unique index trên
     *              `customer_order_id`). Caller quyết định đó là vô hại
     *              (replay sync) hay là tranh chấp phải báo lỗi.
     */
    public function recordRedemption(
        string $couponId,
        string $orderId,
        ?string $customerId,
        float $discount,
        string $via,
        ?string $redeemedByUserId = null,
        ?CarbonImmutable $redeemedAt = null,
    ): bool;

    /**
     * Nhả redemption đang hiệu lực của đơn: khoá dòng, giảm `times_used`, rồi
     * xoá cứng (`$hardDelete`) hoặc đóng dấu `released_at`.
     *
     * Idempotent — đơn không có redemption nào thì không làm gì.
     */
    public function releaseRedemptionForOrder(string $orderId, bool $hardDelete = false): void;

    /**
     * Re-stamp the LIVE redemption row's `discount_applied_amount` for an order
     * (#2154). No-op when the order has no live redemption.
     *
     * Sổ đổi phải mang khoản giảm ĐANG áp, không phải ảnh chụp lúc áp: cả ba
     * chỗ đọc nó (Z-report, ví coupon của khách, lịch sử dùng ở HQ) đều trình
     * bày nó như "đã giảm bao nhiêu". Ảnh chụp lúc áp đã có chỗ ở riêng —
     * `audit_logs` + `customer_orders.coupon_code_snapshot`.
     */
    public function syncRedemptionAmountForOrder(string $orderId, float $amount): void;

    /** Số tiền giảm cho một subtotal, đã làm tròn theo bước tiền tệ. */
    public function computeDiscount(string $couponId, float $subtotal, ?string $currencyCode = null): float;

    /** Mã tiền tệ hiệu lực của chi nhánh, null nếu chưa cấu hình. */
    public function resolveCurrencyForBranch(?string $branchId): ?string;

    /** @throws CouponException coupon không dùng được ở chi nhánh này */
    public function assertBranchEligible(string $couponId, string $branchId): void;

    /** @throws CouponException khách này đã dùng hết lượt */
    public function assertCustomerEligible(string $couponId, ?string $customerId): void;
}
