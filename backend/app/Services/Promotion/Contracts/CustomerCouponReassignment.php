<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

/**
 * #1550 — Pricing công bố: **chuyển coupon và lịch sử dùng coupon của khách A sang B.**
 *
 * Song sinh với `Order\Contracts\CustomerOrderReassignment`, chỉ khác module.
 *
 * Cổng này ôm HAI bảng vì hai rào độc lập chỉ tay vào cùng một chỗ, và mỗi rào
 * chỉ thấy một nửa:
 *
 * - `coupon_redemptions` thuộc aggregate `promotion`, nên
 *   `architecture:domain-writers` đòi lệnh ghi nằm trong biên của aggregate đó;
 * - `coupons` KHÔNG thuộc aggregate nào (`fk_reachability_exempt`: coupon cá
 *   nhân sống độc lập, `customer_id` chỉ tên chủ sở hữu) nên rào đột biến im
 *   lặng — nhưng nó thuộc **module Pricing**, và `RawTableReadsTest` R2 bắt
 *   đúng một `DB::table('coupons')` gọi từ CustomerEngagement, ngân sách 0.
 *
 * Nếu chỉ nghe rào thứ nhất thì `coupons` ở lại phía khách và rào thứ hai đỏ —
 * đó đúng là thứ tự đã xảy ra khi dựng cổng này.
 */
interface CustomerCouponReassignment
{
    /** @return int tổng số dòng đã chuyển chủ, cộng cả hai bảng */
    public function reassignCustomer(string $sourceCustomerId, string $targetCustomerId): int;
}
