<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 — "khuyến mãi này đã được dùng trên những dòng món nào".
 *
 * Câu hỏi thuộc về Pricing, câu TRẢ LỜI nằm trong `customer_order_items` của
 * Ordering. Trước đây `MenuPromotionService` tự đi hỏi: nó import
 * `App\Models\CustomerOrderItem` và chạy thẳng aggregate trên bảng đơn.
 *
 * Cổng này do **Ordering** khai và **Ordering** hiện thực — khác với
 * {@see OrderPaymentLedgerReads} (consumer khai, module kia hiện thực). Lý do:
 * ở đây Ordering là bên SỞ HỮU dữ liệu, nên nó công bố một phép đọc đã đóng gói
 * thay vì để module khác tự viết truy vấn trên bảng của mình. Namespace
 * `App\Services\Order\Contracts` được publish nên mọi module đều thấy.
 *
 * Trả về mảng thuần, KHÔNG trả model — một cổng làm rò model ra ngoài thì
 * không gỡ được cạnh nào cả.
 */
interface PromotionRedemptionReads
{
    /**
     * Tổng hợp cho trang chi tiết khuyến mãi: bao nhiêu dòng món đã áp, tổng
     * tiền giảm, lần áp đầu/cuối.
     *
     * `total_discount_applied` cộng `quantity * max(original_unit_price - unit_price, 0)`
     * — kẹp ở 0 để một dòng có `original_unit_price` nhỏ hơn (dữ liệu bẩn)
     * không trừ ngược vào tổng.
     *
     * @return array{
     *     items_with_promotion_count: int,
     *     total_discount_applied: float,
     *     first_redeemed_at: ?string,
     *     last_redeemed_at: ?string,
     * }
     */
    public function summaryForPromotion(string $promotionId): array;

    /**
     * Các dòng món đã áp khuyến mãi, mới nhất trước.
     *
     * @return list<array{
     *     id: string,
     *     customer_order_id: string,
     *     order_code: ?string,
     *     product_name: ?string,
     *     original_unit_price: ?float,
     *     unit_price: float,
     *     applied_at: string,
     * }>
     */
    public function recentForPromotion(string $promotionId, int $limit): array;
}
